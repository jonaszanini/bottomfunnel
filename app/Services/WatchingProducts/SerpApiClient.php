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
        protected QuotaBudget $budget = new QuotaBudget
    ) {}

    /**
     * Fetch Trends and Search data for a product.
     * Returns array of extracted values without throwing on API errors.
     *
     * @return array{trends: array<string, mixed>, serp: array<string, mixed>, success: bool, error: ?string}
     */
    public function fetchProductData(WatchedProduct $product): array
    {
        $apiKey = config('watching.serpapi_key');
        if (empty($apiKey)) {
            Log::info("SerpApiClient: No SERPAPI_KEY configured. Skipping API call for product {$product->id}.");

            return [
                'trends' => [],
                'serp' => [],
                'success' => false,
                'error' => 'No API key configured',
            ];
        }

        $trendsData = $this->fetchGoogleTrends($product->name, $apiKey);
        $serpData = $this->fetchGoogleSerp($product->name, $apiKey);

        $success = ! empty($trendsData) || ! empty($serpData);

        return [
            'trends' => $trendsData,
            'serp' => $serpData,
            'success' => $success,
            'error' => $success ? null : 'API calls returned empty or failed',
        ];
    }

    /**
     * Query google_trends engine (TIMESERIES, today 3-m).
     *
     * @return array<string, mixed>
     */
    public function fetchGoogleTrends(string $query, ?string $apiKey = null): array
    {
        $apiKey ??= config('watching.serpapi_key');
        if (empty($apiKey)) {
            return [];
        }

        $cacheKey = 'serpapi_trends_'.md5(strtolower(trim($query)));

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($query, $apiKey) {
            try {
                $this->budget->recordCall(1);

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

                return $this->parseTrendsResponse($response->json());
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
    public function fetchGoogleSerp(string $query, ?string $apiKey = null, string $gl = 'us'): array
    {
        $apiKey ??= config('watching.serpapi_key');
        if (empty($apiKey)) {
            return [];
        }

        $cacheKey = 'serpapi_search_'.md5(strtolower(trim($query)).'_'.$gl);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($query, $apiKey, $gl) {
            try {
                $this->budget->recordCall(1);

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
    protected function parseTrendsResponse(array $json): array
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

        // If max was in first half and recent 7d is less than 15% of peak
        if ($maxVal >= 60 && $peakIndex < ($totalPoints * 0.5) && $avg7d < ($maxVal * 0.15)) {
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

        return [
            'trends_7d' => round($avg7d, 2),
            'trends_30d' => round($avg30d, 2),
            'trends_90d' => round($avg90d, 2),
            'trends_trend' => $trend->value,
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
        foreach ($json['organic_results'] ?? [] as $item) {
            if (! empty($item['title'])) {
                $organicTitles[] = (string) $item['title'];
            }
            if (! empty($item['link'])) {
                $host = parse_url($item['link'], PHP_URL_HOST);
                if ($host) {
                    $organicDomains[] = str_ireplace('www.', '', $host);
                }
            }
        }

        // Ads count
        $ads = $json['ads'] ?? [];
        $advertiserCount = count($ads);

        return [
            'related_searches' => array_values(array_unique($relatedSearches)),
            'organic_titles' => $organicTitles,
            'organic_domains' => array_values(array_unique($organicDomains)),
            'advertiser_count' => $advertiserCount,
        ];
    }
}
