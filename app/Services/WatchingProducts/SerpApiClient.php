<?php

namespace App\Services\WatchingProducts;

use App\Enums\TrendsTrend;
use App\Models\WatchedProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SerpApiClient
{
    public function __construct(
        protected QuotaBudget $budget = new QuotaBudget,
        protected BofChecker $bofChecker = new BofChecker
    ) {}

    /**
     * Build a deterministic cache key for a SerpApi query.
     */
    public function buildCacheKey(
        string $type,
        string $query,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null
    ): string {
        $cleanQuery = strtolower(trim($query));
        $cleanGl = strtolower(trim($gl ?? (string) config('watching.serpapi_gl', 'ca')));
        $cleanHl = strtolower(trim($hl ?? 'en'));
        $cleanDevice = strtolower(trim($device ?? 'desktop'));

        $hash = hash('sha256', "{$cleanQuery}|{$cleanGl}|{$cleanHl}|{$cleanDevice}");

        return "serpapi_{$type}_{$hash}";
    }

    /**
     * Fetch Trends and Search data for a product.
     * Returns array of extracted values without throwing on API errors.
     *
     * @return array{trends: array<string, mixed>, serp: array<string, mixed>, bof: array{bof_vpn_serp: bool, bof_image_match_pct: int, bof_translation: bool}, success: bool, error: ?string}
     */
    public function fetchProductData(WatchedProduct $product, bool $forceFresh = false, bool $isManual = false): array
    {
        $apiKey = config('watching.serpapi_key');
        if (empty($apiKey)) {
            Log::info("SerpApiClient: No SERPAPI_KEY configured. Skipping API call for product {$product->id}.");

            return [
                'trends' => [],
                'serp' => [],
                'bof' => $this->bofChecker->check($product, []),
                'success' => false,
                'error' => 'No API key configured',
            ];
        }

        $gl = (string) config('watching.serpapi_gl', 'ca');

        $trendsData = $this->fetchGoogleTrends(
            query: $product->name,
            apiKey: $apiKey,
            gl: $gl,
            forceFresh: $forceFresh,
            isManual: $isManual
        );

        $serpData = $this->fetchGoogleSerp(
            query: $product->name,
            apiKey: $apiKey,
            gl: $gl,
            forceFresh: $forceFresh,
            isManual: $isManual
        );

        $bofData = $this->bofChecker->check($product, $serpData);

        $success = ! empty($trendsData) || ! empty($serpData);

        return [
            'trends' => $trendsData,
            'serp' => $serpData,
            'bof' => $bofData,
            'success' => $success,
            'error' => $success ? null : 'API calls returned empty or failed',
        ];
    }

    /**
     * Query google_trends engine (TIMESERIES, today 3-m).
     *
     * @return array<string, mixed>
     */
    public function fetchGoogleTrends(
        string $query,
        ?string $apiKey = null,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null,
        bool $forceFresh = false,
        bool $isManual = false
    ): array {
        $apiKey ??= config('watching.serpapi_key');
        if (empty($apiKey)) {
            return [];
        }

        $cacheKey = $this->buildCacheKey('trends', $query, $gl, $hl, $device);
        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        $ttlHours = (int) config('watching.serpapi_cache_ttl_hours', 12);

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($query, $apiKey, $isManual) {
            try {
                if (! $this->budget->atomicSpend(1, $isManual)) {
                    Log::warning("SerpApiClient: SerpApi quota reached. Skipping Trends call for '{$query}'.");

                    return [];
                }

                $response = Http::timeout(20)->get('https://serpapi.com/search.json', [
                    'engine' => 'google_trends',
                    'q' => $query,
                    'data_type' => 'TIMESERIES',
                    'date' => 'today 3-m',
                    'api_key' => $apiKey,
                ]);

                if ($response->status() === 429) {
                    Log::warning("SerpApiClient: 429 Rate Limit exceeded for Trends query '{$query}'.");

                    return [];
                }

                if (! $response->successful()) {
                    Log::warning("SerpApiClient: Trends call failed ({$response->status()}): {$response->body()}");

                    return [];
                }

                return $this->parseTrendsResponse($response->json(), $query);
            } catch (Throwable $e) {
                Log::warning("SerpApiClient error fetching Trends for '{$query}': {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Query Google search engine for related searches, organic results, and ads.
     *
     * @return array<string, mixed>
     */
    public function fetchGoogleSerp(
        string $query,
        ?string $apiKey = null,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null,
        bool $forceFresh = false,
        bool $isManual = false
    ): array {
        $apiKey ??= config('watching.serpapi_key');
        if (empty($apiKey)) {
            return [];
        }

        $gl ??= config('watching.serpapi_gl', 'ca');
        $cacheKey = $this->buildCacheKey('search', $query, $gl, $hl, $device);
        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        $ttlHours = (int) config('watching.serpapi_cache_ttl_hours', 12);

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($query, $apiKey, $gl, $isManual) {
            try {
                if (! $this->budget->atomicSpend(1, $isManual)) {
                    Log::warning("SerpApiClient: SerpApi quota reached. Skipping Search call for '{$query}'.");

                    return [];
                }

                $response = Http::timeout(20)->get('https://serpapi.com/search.json', [
                    'engine' => 'google',
                    'q' => $query,
                    'gl' => $gl,
                    'api_key' => $apiKey,
                ]);

                if ($response->status() === 429) {
                    Log::warning("SerpApiClient: 429 Rate Limit exceeded for Google Search query '{$query}'.");

                    return [];
                }

                if (! $response->successful()) {
                    Log::warning("SerpApiClient: Search call failed ({$response->status()}): {$response->body()}");

                    return [];
                }

                return $this->parseSerpResponse($response->json());
            } catch (Throwable $e) {
                Log::warning("SerpApiClient error fetching Google Search for '{$query}': {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Parse timeline points from google_trends response.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected function parseTrendsResponse(array $json, string $query = ''): array
    {
        $timelineData = $json['interest_over_time']['timeline_data'] ?? [];
        if (empty($timelineData) || ! is_array($timelineData)) {
            return [
                'trends_7d' => 0.0,
                'trends_30d' => 0.0,
                'trends_90d' => 0.0,
                'trends_trend' => TrendsTrend::Stable->value,
            ];
        }

        $values = [];
        foreach ($timelineData as $point) {
            $val = $point['values'][0]['extracted_value'] ?? $point['values'][0]['value'] ?? 0;
            $values[] = (float) $val;
        }

        $totalPoints = count($values);
        if ($totalPoints === 0) {
            return [];
        }

        // Approximate points for 7d (~10% of 90d points), 30d (~33%), and 90d (100%)
        $slice7 = array_slice($values, -max(1, (int) round($totalPoints * 0.1)));
        $slice30 = array_slice($values, -max(1, (int) round($totalPoints * 0.35)));

        $avg7d = count($slice7) > 0 ? array_sum($slice7) / count($slice7) : 0.0;
        $avg30d = count($slice30) > 0 ? array_sum($slice30) / count($slice30) : 0.0;
        $avg90d = array_sum($values) / $totalPoints;

        // Peak analysis for dead_post_peak
        $maxVal = max($values);
        $peakIndex = array_search($maxVal, $values, true);
        $isDeadPostPeak = false;

        $minPeak = (float) config('watching.thresholds.dead_post_peak_min_peak', 60.0);
        $ratio7d = (float) config('watching.thresholds.dead_post_peak_7d_ratio', 0.15);
        $ratio30d = (float) config('watching.thresholds.dead_post_peak_30d_ratio', 0.25);

        // A true post-peak collapse requires:
        // 1. A significant historical peak (maxVal >= minPeak, default 60)
        // 2. The peak occurred in the first half of the 90d window ($peakIndex < $totalPoints * 0.5)
        // 3. Both recent 7d (<15% of peak) AND 30d (<25% of peak) have collapsed.
        // Flat low or moderate volume throughout is NOT dead_post_peak.
        if ($maxVal >= $minPeak
            && $peakIndex < ($totalPoints * 0.5)
            && $avg7d < ($maxVal * $ratio7d)
            && $avg30d < ($maxVal * $ratio30d)
        ) {
            $isDeadPostPeak = true;
        }

        $trend = TrendsTrend::Stable;
        if ($isDeadPostPeak) {
            $trend = TrendsTrend::DeadPostPeak;
        } elseif ($avg7d > ($avg30d * 1.2)) {
            $trend = TrendsTrend::Rising;
        } elseif ($avg7d < ($avg30d * 0.8)) {
            $trend = TrendsTrend::Falling;
        }

        Log::info("SerpApiClient Trends analyzed: query='{$query}', 7d=".round($avg7d, 2).', 30d='.round($avg30d, 2).', 90d='.round($avg90d, 2).", peak={$maxVal} at idx={$peakIndex}/{$totalPoints}, is_dead_post_peak=".($isDeadPostPeak ? 'yes' : 'no').", trend={$trend->value}");

        return [
            'trends_7d' => round($avg7d, 2),
            'trends_30d' => round($avg30d, 2),
            'trends_90d' => round($avg90d, 2),
            'trends_trend' => $trend->value,
            'trends_peak' => round($maxVal, 2),
            'trends_peak_index' => $peakIndex,
            'trends_total_points' => $totalPoints,
            'is_dead_post_peak' => $isDeadPostPeak,
        ];
    }

    /**
     * Parse organic results, ads, and related searches from google search response.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected function parseSerpResponse(array $json): array
    {
        // Related searches
        $relatedSearches = [];
        foreach ($json['related_searches'] ?? [] as $item) {
            if (! empty($item['query'])) {
                $relatedSearches[] = (string) $item['query'];
            }
        }

        // Organic results: titles and domains
        $organicTitles = [];
        $organicDomains = [];
        $organicResults = [];
        foreach ($json['organic_results'] ?? [] as $item) {
            $title = (string) ($item['title'] ?? '');
            if ($title !== '') {
                $organicTitles[] = $title;
            }
            $link = (string) ($item['link'] ?? '');
            if ($link !== '') {
                $host = parse_url($link, PHP_URL_HOST);
                if ($host) {
                    $organicDomains[] = str_ireplace('www.', '', $host);
                }
            }
            $organicResults[] = [
                'title' => $title,
                'snippet' => (string) ($item['snippet'] ?? ''),
                'link' => $link,
                'thumbnail' => $item['thumbnail'] ?? null,
                'rich_snippet' => $item['rich_snippet'] ?? null,
            ];
        }

        // Ads count and domains/titles
        $ads = $json['ads'] ?? [];
        $advertiserCount = count($ads);
        $adDomains = [];
        $adTitles = [];
        $adsList = [];
        foreach ($ads as $ad) {
            $adTitle = (string) ($ad['title'] ?? '');
            if ($adTitle !== '') {
                $adTitles[] = $adTitle;
            }
            $adLink = (string) ($ad['link'] ?? $ad['tracking_link'] ?? $ad['displayed_link'] ?? '');
            if (! empty($ad['displayed_link'])) {
                $dispLink = (string) $ad['displayed_link'];
                $host = parse_url(str_starts_with($dispLink, 'http') ? $dispLink : 'https://'.$dispLink, PHP_URL_HOST) ?: $dispLink;
                $adDomains[] = str_ireplace('www.', '', (string) $host);
            } elseif ($adLink !== '') {
                $host = parse_url($adLink, PHP_URL_HOST);
                if ($host) {
                    $adDomains[] = str_ireplace('www.', '', $host);
                }
            }
            $adsList[] = [
                'title' => $adTitle,
                'snippet' => (string) ($ad['snippet'] ?? ''),
                'link' => $adLink,
            ];
        }

        return [
            'related_searches' => array_values(array_unique($relatedSearches)),
            'organic_titles' => $organicTitles,
            'organic_domains' => array_values(array_unique($organicDomains)),
            'organic_results' => $organicResults,
            'ad_titles' => array_values(array_unique($adTitles)),
            'ad_domains' => array_values(array_unique($adDomains)),
            'ads' => $adsList,
            'inline_images' => $json['inline_images'] ?? [],
            'shopping_results' => $json['shopping_results'] ?? $json['inline_shopping'] ?? [],
            'advertiser_count' => $advertiserCount,
        ];
    }
}
