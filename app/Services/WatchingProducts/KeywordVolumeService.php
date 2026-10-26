<?php

namespace App\Services\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\GoogleAdsAccount;
use App\Models\WatchedProduct;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleAdsQuotaException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class KeywordVolumeService
{
    public function __construct(
        protected GoogleAdsClient $client = new GoogleAdsClient
    ) {}

    /**
     * Resolve the active Google Ads account to query Keyword Planner.
     * Prefers config override, then default account, then most recently linked active account with a refresh token.
     */
    public function resolveAccount(): ?GoogleAdsAccount
    {
        $configAccountId = config('flowhub.keyword_volume.account_id');
        if (! empty($configAccountId)) {
            $account = GoogleAdsAccount::query()->find($configAccountId)
                ?? GoogleAdsAccount::query()->where('customer_id', preg_replace('/\D/', '', (string) $configAccountId))->first();
            if ($account) {
                return $account;
            }
        }

        // 1. Prefer default account
        $defaultAccount = GoogleAdsAccount::query()
            ->where('is_default', true)
            ->whereNotNull('refresh_token')
            ->first();
        if ($defaultAccount) {
            return $defaultAccount;
        }

        // 2. Most recently linked active account with refresh token
        return GoogleAdsAccount::query()
            ->whereNotNull('refresh_token')
            ->whereIn('status', ['active', 'linked'])
            ->orderByDesc('id')
            ->first()
            ?? GoogleAdsAccount::query()
                ->whereNotNull('refresh_token')
                ->orderByDesc('id')
                ->first();
    }

    /**
     * Refresh search volume for a single watched product.
     *
     * @return array{success: bool, error: ?string, product: WatchedProduct}
     */
    public function refreshProduct(WatchedProduct $product): array
    {
        $account = $this->resolveAccount();
        if (! $account) {
            $error = 'No active Google Ads account found with a refresh token.';
            $product->update([
                'search_volume_error' => $error,
            ]);

            return ['success' => false, 'error' => $error, 'product' => $product];
        }

        $summary = $this->refreshProducts(collect([$product]), $account);

        $product->refresh();

        return [
            'success' => empty($product->search_volume_error),
            'error' => $product->search_volume_error,
            'product' => $product,
        ];
    }

    /**
     * Batch refresh search volume for a collection of products.
     *
     * @param  Collection<int, WatchedProduct>  $products
     * @return array{updated: int, nulls: int, ranges: int, errors: int}
     *
     * @throws GoogleAdsQuotaException
     */
    public function refreshProducts(Collection $products, ?GoogleAdsAccount $account = null): array
    {
        // Filter out rejected products
        $eligibleProducts = $products->reject(fn (WatchedProduct $p) => $p->status === WatchedProductStatus::Rejected)->values();
        if ($eligibleProducts->isEmpty()) {
            return ['updated' => 0, 'nulls' => 0, 'ranges' => 0, 'errors' => 0];
        }

        $account ??= $this->resolveAccount();
        if (! $account) {
            $msg = 'No active Google Ads account found with a refresh token.';
            foreach ($eligibleProducts as $p) {
                $p->update(['search_volume_error' => $msg]);
            }

            return ['updated' => 0, 'nulls' => 0, 'ranges' => 0, 'errors' => $eligibleProducts->count()];
        }

        $geoMap = (array) config('flowhub.keyword_volume.geos', [
            'US' => '2840',
            'CA' => '2124',
            'GB' => '2826',
            'AU' => '2036',
        ]);
        $language = (string) config('flowhub.keyword_volume.language', 'languageConstants/1000');

        // Group products by geo: ['US' => [product1, product2], 'CA' => ...]
        $geoToProducts = [];
        foreach ($eligibleProducts as $product) {
            $geos = $product->searchVolumeGeos();
            foreach ($geos as $geo) {
                if (isset($geoMap[$geo])) {
                    $geoToProducts[$geo][] = $product;
                }
            }
        }

        // Structure to collect per-product geo metrics: [$productId => [$geo => $metricData]]
        $productGeoMetrics = [];
        $productErrors = [];
        $quotaException = null;
        $processedGeos = [];

        // For each geo, batch all keywords into API calls
        foreach ($geoToProducts as $geo => $productsInGeo) {
            $processedGeos[] = $geo;
            $geoConstantId = $geoMap[$geo];

            // Collect unique keywords for this geo
            $keywords = array_values(array_unique(array_map(
                fn (WatchedProduct $p) => $p->effectiveSearchVolumeKeyword(),
                $productsInGeo
            )));

            try {
                $results = $this->client->keywordHistoricalMetrics($account, $keywords, [$geoConstantId], $language);

                // Index results by lowercased keyword
                $resultsByKeyword = [];
                foreach ($results as $item) {
                    $resultsByKeyword[strtolower($item['keyword'])] = $item;
                }

                foreach ($productsInGeo as $product) {
                    $kwLower = strtolower($product->effectiveSearchVolumeKeyword());
                    if (isset($resultsByKeyword[$kwLower])) {
                        $productGeoMetrics[$product->id][$geo] = $resultsByKeyword[$kwLower];
                    } else {
                        // Keyword returned no metrics from Google Ads
                        $productGeoMetrics[$product->id][$geo] = [
                            'keyword' => $product->effectiveSearchVolumeKeyword(),
                            'avg_monthly_searches' => null,
                            'min' => null,
                            'max' => null,
                            'competition' => 'UNSPECIFIED',
                            'competition_index' => null,
                            'low_bid_micros' => null,
                            'high_bid_micros' => null,
                            'monthly' => [],
                            'is_range' => false,
                        ];
                    }
                }
            } catch (GoogleAdsQuotaException $e) {
                // Stop querying further geos, but persist what was already fetched.
                Log::error('KeywordVolumeService: Google Ads quota exhausted during refresh: '.$e->getMessage());
                $quotaException = $e;
                foreach ($productsInGeo as $product) {
                    $productErrors[$product->id][$geo] = 'Google Ads quota exhausted: '.$e->getMessage();
                }
                // Geos never reached because of the quota break are recorded as errors too.
                foreach (array_diff(array_keys($geoToProducts), $processedGeos) as $skippedGeo) {
                    foreach ($geoToProducts[$skippedGeo] as $product) {
                        $productErrors[$product->id][$skippedGeo] = "Google Ads ({$skippedGeo}): skipped, quota exhausted";
                    }
                }
                break;
            } catch (\Throwable $e) {
                Log::warning("KeywordVolumeService: Error refreshing volume for geo {$geo}: {$e->getMessage()}");
                foreach ($productsInGeo as $product) {
                    $productErrors[$product->id][$geo] = 'Google Ads ('.$geo.'): '.$e->getMessage();
                }
            }
        }

        $updatedCount = 0;
        $nullCount = 0;
        $rangeCount = 0;
        $errorCount = 0;

        // Persist computed volume per product
        foreach ($eligibleProducts as $product) {
            $existingByGeo = (array) ($product->search_volume_by_geo ?? []);
            $newGeos = $productGeoMetrics[$product->id] ?? [];

            // Merge newly fetched geos into product's geo breakdown
            $mergedByGeo = array_merge($existingByGeo, $newGeos);

            $targetGeos = $product->searchVolumeGeos();
            $totalSearches = 0;
            $hasAnyNonZero = false;
            $isRange = false;

            foreach ($targetGeos as $g) {
                if (isset($mergedByGeo[$g])) {
                    $geoData = $mergedByGeo[$g];
                    if (isset($geoData['avg_monthly_searches']) && is_numeric($geoData['avg_monthly_searches'])) {
                        $totalSearches += (int) $geoData['avg_monthly_searches'];
                        $hasAnyNonZero = true;
                    }
                    if (! empty($geoData['is_range'])) {
                        $isRange = true;
                    }
                }
            }

            $searchVolumeTotal = $hasAnyNonZero ? $totalSearches : null;
            $geoErrors = $productErrors[$product->id] ?? [];
            $error = empty($geoErrors) ? null : implode('; ', $geoErrors);

            if ($error !== null) {
                $errorCount++;
                // Keep checked_at at the last successful fetch; merge any geos that did succeed.
                $attrs = [
                    'search_volume_error' => mb_substr($error, 0, 255),
                    'search_volume_error_at' => now(),
                ];
                if (! empty($newGeos)) {
                    $attrs['search_volume_by_geo'] = $mergedByGeo;
                    $attrs['search_volume_total'] = $searchVolumeTotal;
                    $attrs['search_volume_is_range'] = $isRange;
                }
                $product->update($attrs);
            } else {
                $product->update([
                    'search_volume_by_geo' => $mergedByGeo,
                    'search_volume_total' => $searchVolumeTotal,
                    'search_volume_is_range' => $isRange,
                    'search_volume_checked_at' => now(),
                    'search_volume_error' => null,
                    'search_volume_error_at' => null,
                ]);

                $updatedCount++;
                if ($searchVolumeTotal === null) {
                    $nullCount++;
                }
                if ($isRange) {
                    $rangeCount++;
                }
            }
        }

        if ($quotaException !== null) {
            throw $quotaException;
        }

        return [
            'updated' => $updatedCount,
            'nulls' => $nullCount,
            'ranges' => $rangeCount,
            'errors' => $errorCount,
        ];
    }
}
