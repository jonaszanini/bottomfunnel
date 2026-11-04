<?php

namespace App\Services\Seo;

use App\Enums\TrendsTrend;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\QuotaBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataForSeoClient implements SeoClientContract
{
    /**
     * Common ISO alpha-2 country codes to DataForSEO location codes mapping.
     *
     * @var array<string, int>
     */
    protected const GEO_LOCATION_MAP = [
        'ca' => 2124, // Canada
        'us' => 2840, // United States
        'gb' => 2826, // United Kingdom
        'uk' => 2826, // United Kingdom alias
        'au' => 2036, // Australia
        'nz' => 2554, // New Zealand
        'de' => 2276, // Germany
        'fr' => 2250, // France
        'es' => 2724, // Spain
        'it' => 2380, // Italy
        'br' => 2076, // Brazil
        'mx' => 2484, // Mexico
        'nl' => 2528, // Netherlands
    ];

    public function __construct(
        protected QuotaBudget $budget = new QuotaBudget,
        protected BofChecker $bofChecker = new BofChecker,
        protected ?string $login = null,
        protected ?string $password = null,
        protected ?string $apiKey = null
    ) {
        $this->login = $login ?? config('watching.dataforseo.login', env('DATAFORSEO_LOGIN'));
        $this->password = $password ?? config('watching.dataforseo.password', env('DATAFORSEO_PASSWORD'));
        $this->apiKey = $apiKey ?? config('watching.dataforseo.api_key', env('DATAFORSEO_API_KEY'));
    }

    /**
     * Resolve Basic Auth credentials [login, password].
     * Supports either DATAFORSEO_LOGIN + DATAFORSEO_PASSWORD or DATAFORSEO_API_KEY.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function getAuthCredentials(): ?array
    {
        if (! empty($this->login) && ! empty($this->password)) {
            return [(string) $this->login, (string) $this->password];
        }

        if (! empty($this->apiKey)) {
            $key = (string) $this->apiKey;
            if (str_contains($key, ':')) {
                [$login, $password] = explode(':', $key, 2);

                return [$login, $password];
            }

            return [$key, ''];
        }

        return null;
    }

    /**
     * Check if client has authentication credentials configured.
     */
    public function isConfigured(): bool
    {
        return $this->getAuthCredentials() !== null;
    }

    /**
     * Fetch current DataForSEO account balance, statistics, and limits.
     * Cached for 5-10 minutes to avoid hitting the endpoint on every page load.
     *
     * @return array{balance: ?float, total_deposited: ?float, statistics: mixed, limits: mixed, today_spend: ?float, total_spent: ?float}
     */
    public function userData(bool $forceFresh = false): array
    {
        $cacheKey = 'dataforseo_user_data';

        if (! $forceFresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $creds = $this->getAuthCredentials();
        if (! $creds) {
            Log::warning('DataForSeoClient: No credentials configured to fetch user data.');

            return [
                'balance' => null,
                'total_deposited' => null,
                'statistics' => null,
                'limits' => null,
                'today_spend' => null,
                'total_spent' => null,
            ];
        }

        $endpoint = 'https://api.dataforseo.com/v3/appendix/user_data';

        try {
            $response = Http::withBasicAuth($creds[0], $creds[1])
                ->timeout(15)
                ->get($endpoint);

            if (! $response->successful()) {
                Log::warning("DataForSeoClient: user_data call failed ({$response->status()}): {$response->body()}");

                return [
                    'balance' => null,
                    'total_deposited' => null,
                    'statistics' => null,
                    'limits' => null,
                    'today_spend' => null,
                    'total_spent' => null,
                ];
            }

            $json = $response->json();
            if (! is_array($json)) {
                Log::warning('DataForSeoClient: user_data returned non-array JSON response.');

                return [
                    'balance' => null,
                    'total_deposited' => null,
                    'statistics' => null,
                    'limits' => null,
                    'today_spend' => null,
                    'total_spent' => null,
                ];
            }

            if (isset($json['status_code']) && (int) $json['status_code'] !== 20000) {
                $statusMsg = (string) ($json['status_message'] ?? 'Unknown error');
                Log::warning("DataForSeoClient: user_data returned error status ({$json['status_code']}): {$statusMsg}");

                return [
                    'balance' => null,
                    'total_deposited' => null,
                    'statistics' => null,
                    'limits' => null,
                    'today_spend' => null,
                    'total_spent' => null,
                ];
            }

            $task = $json['tasks'][0] ?? null;
            $result = is_array($task) && isset($task['result'][0])
                ? $task['result'][0]
                : ($json['result'][0] ?? $json);

            $money = is_array($result) && isset($result['money']) && is_array($result['money'])
                ? $result['money']
                : (isset($json['money']) && is_array($json['money']) ? $json['money'] : []);

            $balance = isset($money['balance']) && is_numeric($money['balance'])
                ? (float) $money['balance']
                : (isset($json['balance']) && is_numeric($json['balance']) ? (float) $json['balance'] : null);

            $totalDeposited = isset($money['total']) && is_numeric($money['total'])
                ? (float) $money['total']
                : (isset($json['total_deposited']) && is_numeric($json['total_deposited']) ? (float) $json['total_deposited'] : null);

            $statistics = is_array($result) && array_key_exists('statistics', $result)
                ? $result['statistics']
                : ($json['statistics'] ?? null);

            $limits = is_array($result) && array_key_exists('limits', $result)
                ? $result['limits']
                : ($json['limits'] ?? null);

            $todaySpend = null;
            if (is_array($statistics) && isset($statistics['day'])) {
                if (is_numeric($statistics['day'])) {
                    $todaySpend = (float) $statistics['day'];
                } elseif (is_array($statistics['day'])) {
                    $sum = 0.0;
                    $hasCost = false;
                    array_walk_recursive($statistics['day'], function ($value, $key) use (&$sum, &$hasCost) {
                        if ($key === 'cost' && is_numeric($value)) {
                            $sum += (float) $value;
                            $hasCost = true;
                        }
                    });
                    if ($hasCost) {
                        $todaySpend = round($sum, 4);
                    }
                }
            }

            $totalSpent = ($totalDeposited !== null && $balance !== null)
                ? round(max(0, $totalDeposited - $balance), 2)
                : null;

            $normalized = [
                'balance' => $balance,
                'total_deposited' => $totalDeposited,
                'statistics' => $statistics,
                'limits' => $limits,
                'today_spend' => $todaySpend,
                'total_spent' => $totalSpent,
            ];

            Cache::put($cacheKey, $normalized, now()->addMinutes(10));

            return $normalized;
        } catch (Throwable $e) {
            Log::warning("DataForSeoClient: Exception fetching user_data: {$e->getMessage()}");

            return [
                'balance' => null,
                'total_deposited' => null,
                'statistics' => null,
                'limits' => null,
                'today_spend' => null,
                'total_spent' => null,
            ];
        }
    }

    /**
     * Build a deterministic cache key for a DataForSEO query.
     */
    public function buildCacheKey(
        string $type,
        string $query,
        ?string $gl = null,
        ?string $hl = null,
        ?string $device = null
    ): string {
        $cleanQuery = strtolower(trim($query));
        $cleanGl = strtolower(trim($gl ?? (string) config('watching.dataforseo.location_name', 'ca')));
        $cleanHl = strtolower(trim($hl ?? (string) config('watching.dataforseo.language_code', 'en')));
        $cleanDevice = strtolower(trim($device ?? 'desktop'));

        $hash = hash('sha256', "{$cleanQuery}|{$cleanGl}|{$cleanHl}|{$cleanDevice}");

        return "dataforseo_{$type}_{$hash}";
    }

    /**
     * Fetch Trends and Search data for a product.
     *
     * @return array{trends: array<string, mixed>, serp: array<string, mixed>, bof: array{bof_vpn_serp: bool, bof_image_match_pct: int, bof_translation: bool}, success: bool, error: ?string}
     */
    public function fetchProductData(WatchedProduct $product, bool $forceFresh = false, bool $isManual = false): array
    {
        if (! $this->isConfigured()) {
            Log::info("DataForSeoClient: No DataForSEO credentials configured. Skipping API call for product {$product->id}.");

            return [
                'trends' => [],
                'serp' => [],
                'bof' => $this->bofChecker->check($product, []),
                'success' => false,
                'error' => 'No DataForSEO credentials configured',
            ];
        }

        $gl = (string) config('watching.dataforseo.location_name', config('watching.serpapi_gl', 'ca'));

        $trendsData = $this->fetchGoogleTrends(
            query: $product->name,
            gl: $gl,
            forceFresh: $forceFresh,
            isManual: $isManual
        );

        $serpData = $this->fetchGoogleSerp(
            query: $product->name,
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
     * Query Google Trends time series via DataForSEO Live Explore endpoint.
     * Endpoint: /v3/keywords_data/google_trends/explore/live
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
        if (! $this->isConfigured()) {
            return [];
        }

        $gl ??= (string) config('watching.dataforseo.location_name', config('watching.serpapi_gl', 'ca'));
        $hl ??= (string) config('watching.dataforseo.language_code', 'en');

        $cacheKey = $this->buildCacheKey('trends', $query, $gl, $hl, $device);
        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        $ttlHours = (int) config('watching.dataforseo.cache_ttl_hours', config('watching.serpapi_cache_ttl_hours', 12));

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($query, $gl, $isManual) {
            try {
                if (! $this->budget->atomicSpend(1, $isManual, 'dataforseo')) {
                    Log::warning("DataForSeoClient: Quota reached. Skipping Trends call for '{$query}'.");

                    return [];
                }

                return $this->googleTrends([$query], $gl, 'past_90_days');
            } catch (Throwable $e) {
                Log::warning("DataForSeoClient error fetching Trends for '{$query}': {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Direct DataForSEO Google Trends Live endpoint call:
     * POST /v3/keywords_data/google_trends/explore/live
     *
     * @param  array<int, string>  $keywords
     * @param  array<int, string>|string  $geo
     * @return array<string, mixed>
     */
    public function googleTrends(array $keywords, array|string $geo = 'ca', string $timeRange = 'past_90_days'): array
    {
        $creds = $this->getAuthCredentials();
        if (! $creds) {
            Log::warning('DataForSeoClient: Attempted googleTrends call without credentials.');

            return [];
        }

        $normalizedTimeRange = $this->normalizeTimeRange($timeRange);
        $locationPayload = $this->resolveLocationPayload($geo);

        $itemPayload = array_merge([
            'keywords' => array_values(array_slice($keywords, 0, 5)),
            'time_range' => $normalizedTimeRange,
            'type' => 'web',
            'language_code' => config('watching.dataforseo.language_code', 'en'),
        ], $locationPayload);

        $endpoint = 'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live';

        try {
            $response = Http::withBasicAuth($creds[0], $creds[1])
                ->timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, [$itemPayload]);

            if ($response->status() === 429) {
                Log::warning('DataForSeoClient: 429 Rate Limit exceeded for Google Trends.');

                return [];
            }

            if (! $response->successful()) {
                Log::warning("DataForSeoClient: Trends call failed ({$response->status()}): {$response->body()}");

                return [];
            }

            $json = $response->json();
            if (! is_array($json)) {
                return [];
            }

            $firstKeyword = reset($keywords) ?: '';

            return $this->parseTrendsResponse($json, $firstKeyword);
        } catch (Throwable $e) {
            Log::warning("DataForSeoClient: Exception in googleTrends: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Query Google search engine for organic results, ads, images, shopping, and related searches.
     * Endpoint: /v3/serp/google/organic/live/advanced
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
        if (! $this->isConfigured()) {
            return [];
        }

        $gl ??= (string) config('watching.dataforseo.location_name', config('watching.serpapi_gl', 'ca'));
        $hl ??= (string) config('watching.dataforseo.language_code', 'en');
        $device ??= 'desktop';

        $cacheKey = $this->buildCacheKey('search', $query, $gl, $hl, $device);
        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        $ttlHours = (int) config('watching.dataforseo.cache_ttl_hours', config('watching.serpapi_cache_ttl_hours', 12));

        return Cache::remember($cacheKey, now()->addHours($ttlHours), function () use ($query, $gl, $hl, $device, $isManual) {
            try {
                if (! $this->budget->atomicSpend(1, $isManual, 'dataforseo')) {
                    Log::warning("DataForSeoClient: Quota reached. Skipping Search call for '{$query}'.");

                    return [];
                }

                return $this->googleSerp($query, $gl, [
                    'language_code' => $hl,
                    'device' => $device,
                ]);
            } catch (Throwable $e) {
                Log::warning("DataForSeoClient error fetching Google Search for '{$query}': {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Direct DataForSEO Google Organic Live Advanced endpoint call:
     * POST /v3/serp/google/organic/live/advanced
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function googleSerp(string $query, string|array $geo = 'ca', array $options = []): array
    {
        $creds = $this->getAuthCredentials();
        if (! $creds) {
            Log::warning('DataForSeoClient: Attempted googleSerp call without credentials.');

            return [];
        }

        $locationPayload = $this->resolveLocationPayload($geo);

        $itemPayload = array_merge([
            'keyword' => $query,
            'language_code' => config('watching.dataforseo.language_code', 'en'),
            'device' => 'desktop',
        ], $locationPayload, $options);

        $endpoint = 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced';

        try {
            $response = Http::withBasicAuth($creds[0], $creds[1])
                ->timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, [$itemPayload]);

            if ($response->status() === 429) {
                Log::warning("DataForSeoClient: 429 Rate Limit exceeded for Google Search query '{$query}'.");

                return [];
            }

            if (! $response->successful()) {
                Log::warning("DataForSeoClient: Search call failed ({$response->status()}): {$response->body()}");

                return [];
            }

            $json = $response->json();
            if (! is_array($json)) {
                return [];
            }

            return $this->parseSerpResponse($json);
        } catch (Throwable $e) {
            Log::warning("DataForSeoClient: Exception in googleSerp for '{$query}': {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Parse timeline points from DataForSEO google_trends response.
     * Matches the exact output shape and scoring formulas of SerpApiClient.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function parseTrendsResponse(array $json, string $query = ''): array
    {
        $defaultEmpty = [
            'trends_7d' => 0.0,
            'trends_30d' => 0.0,
            'trends_90d' => 0.0,
            'trends_trend' => TrendsTrend::Unknown->value,
            'trends_peak' => 0.0,
            'trends_peak_index' => null,
            'trends_total_points' => 0,
            'is_dead_post_peak' => false,
        ];

        // Check top-level status
        if (isset($json['status_code']) && (int) $json['status_code'] !== 20000) {
            Log::warning("DataForSeoClient Trends status code not ok: {$json['status_code']}");

            return $defaultEmpty;
        }

        $task = $json['tasks'][0] ?? null;
        if (! is_array($task)) {
            return $defaultEmpty;
        }

        if (isset($task['status_code']) && (int) $task['status_code'] !== 20000) {
            Log::warning("DataForSeoClient Trends task status code: {$task['status_code']}");

            return $defaultEmpty;
        }

        // DataForSEO can return items in result[0].items or data.items
        $items = $task['result'][0]['items'] ?? $task['data']['items'] ?? [];
        if (! is_array($items)) {
            return $defaultEmpty;
        }

        $values = [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? $item['explore_type'] ?? '');
            if ($type === 'google_trends_graph' || $type === 'interest_over_time' || isset($item['data'])) {
                foreach ($item['data'] ?? [] as $point) {
                    // Extract point values array (or single scalar value)
                    $val = null;
                    if (isset($point['values'][0])) {
                        $val = $point['values'][0];
                    } elseif (isset($point['value'])) {
                        $val = $point['value'];
                    }

                    if ($val !== null && is_numeric($val)) {
                        $values[] = (float) $val;
                    }
                }

                if (! empty($values)) {
                    break;
                }
            }
        }

        $totalPoints = count($values);
        if ($totalPoints === 0) {
            return $defaultEmpty;
        }

        // Approximate points for 7d (~10% of 90d points), 30d (~35%), and 90d (100%)
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
        if ($maxVal >= $minPeak
            && $peakIndex < ($totalPoints * 0.5)
            && $avg7d < ($maxVal * $ratio7d)
            && $avg30d < ($maxVal * $ratio30d)
        ) {
            $isDeadPostPeak = true;
        }

        $trend = TrendsTrend::Stable;
        if ($maxVal <= 0.0) {
            $trend = TrendsTrend::Unknown;
        } elseif ($isDeadPostPeak) {
            $trend = TrendsTrend::DeadPostPeak;
        } elseif ($avg7d > ($avg30d * 1.2)) {
            $trend = TrendsTrend::Rising;
        } elseif ($avg7d < ($avg30d * 0.8)) {
            $trend = TrendsTrend::Falling;
        }

        Log::info("DataForSeoClient Trends analyzed: query='{$query}', 7d=".round($avg7d, 2).', 30d='.round($avg30d, 2).', 90d='.round($avg90d, 2).", peak={$maxVal} at idx={$peakIndex}/{$totalPoints}, is_dead_post_peak=".($isDeadPostPeak ? 'yes' : 'no').", trend={$trend->value}");

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
     * Parse organic results, ads, images, shopping, and related searches from DataForSEO SERP response.
     * Matches the exact output shape and keys expected by downstream callers.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function parseSerpResponse(array $json): array
    {
        $relatedSearches = [];
        $organicTitles = [];
        $organicDomains = [];
        $organicResults = [];
        $adTitles = [];
        $adDomains = [];
        $adsList = [];
        $inlineImages = [];
        $shoppingResults = [];
        $advertiserCount = 0;

        $task = $json['tasks'][0] ?? null;
        $items = $task['result'][0]['items'] ?? $json['items'] ?? [];

        if (is_array($items)) {
            foreach ($items as $item) {
                $type = (string) ($item['type'] ?? '');

                // 1. Organic results
                if ($type === 'organic') {
                    $title = (string) ($item['title'] ?? '');
                    if ($title !== '') {
                        $organicTitles[] = $title;
                    }

                    $link = (string) ($item['url'] ?? $item['link'] ?? '');
                    if ($link !== '') {
                        $host = parse_url($link, PHP_URL_HOST) ?: ($item['domain'] ?? null);
                        if ($host) {
                            $organicDomains[] = str_ireplace('www.', '', (string) $host);
                        }
                    }

                    $organicResults[] = [
                        'title' => $title,
                        'snippet' => (string) ($item['description'] ?? $item['snippet'] ?? ''),
                        'link' => $link,
                        'thumbnail' => $item['thumbnail'] ?? null,
                        'rich_snippet' => $item['highlighted'] ?? $item['rich_snippet'] ?? null,
                    ];
                }

                // 2. Paid Ads
                elseif ($type === 'paid' || $type === 'ads') {
                    $advertiserCount++;
                    $adTitle = (string) ($item['title'] ?? '');
                    if ($adTitle !== '') {
                        $adTitles[] = $adTitle;
                    }

                    $adLink = (string) ($item['url'] ?? $item['link'] ?? '');
                    $host = parse_url($adLink, PHP_URL_HOST) ?: ($item['domain'] ?? null);
                    if ($host) {
                        $adDomains[] = str_ireplace('www.', '', (string) $host);
                    }

                    $adsList[] = [
                        'title' => $adTitle,
                        'snippet' => (string) ($item['description'] ?? $item['snippet'] ?? ''),
                        'link' => $adLink,
                    ];
                }

                // 3. Related searches
                elseif ($type === 'related_searches') {
                    $subItems = $item['items'] ?? [];
                    if (is_array($subItems)) {
                        foreach ($subItems as $sub) {
                            if (is_string($sub) && trim($sub) !== '') {
                                $relatedSearches[] = trim($sub);
                            } elseif (is_array($sub)) {
                                $q = $sub['keyword'] ?? $sub['query'] ?? $sub['title'] ?? '';
                                if (! empty($q)) {
                                    $relatedSearches[] = (string) $q;
                                }
                            }
                        }
                    }
                }

                // 4. Images
                elseif ($type === 'images') {
                    $subItems = $item['items'] ?? [];
                    if (is_array($subItems)) {
                        foreach ($subItems as $img) {
                            $imgTitle = (string) ($img['alt'] ?? $img['title'] ?? '');
                            $imgUrl = (string) ($img['url'] ?? '');
                            $source = (string) (parse_url($imgUrl, PHP_URL_HOST) ?: ($img['domain'] ?? ''));

                            $inlineImages[] = [
                                'title' => $imgTitle,
                                'source' => $source,
                                'thumbnail' => $img['image_url'] ?? $img['thumbnail'] ?? null,
                            ];
                        }
                    }
                }

                // 5. Shopping results
                elseif ($type === 'shopping') {
                    $subItems = $item['items'] ?? [];
                    if (is_array($subItems)) {
                        foreach ($subItems as $shop) {
                            $shoppingResults[] = [
                                'title' => (string) ($shop['title'] ?? ''),
                                'price' => $shop['price'] ?? null,
                                'domain' => (string) ($shop['domain'] ?? ''),
                            ];
                        }
                    }
                }
            }
        }

        // Support direct top-level arrays if provided in test fixtures or mock data
        if (empty($relatedSearches) && ! empty($json['related_searches'])) {
            foreach ($json['related_searches'] as $r) {
                $q = is_string($r) ? $r : ($r['query'] ?? $r['keyword'] ?? '');
                if ($q !== '') {
                    $relatedSearches[] = (string) $q;
                }
            }
        }

        if (empty($inlineImages) && ! empty($json['inline_images'])) {
            $inlineImages = $json['inline_images'];
        }

        if (empty($shoppingResults) && ! empty($json['shopping_results'])) {
            $shoppingResults = $json['shopping_results'];
        }

        return [
            'related_searches' => array_values(array_unique(array_filter($relatedSearches))),
            'organic_titles' => $organicTitles,
            'organic_domains' => array_values(array_unique(array_filter($organicDomains))),
            'organic_results' => $organicResults,
            'ad_titles' => array_values(array_unique(array_filter($adTitles))),
            'ad_domains' => array_values(array_unique(array_filter($adDomains))),
            'ads' => $adsList,
            'inline_images' => $inlineImages,
            'shopping_results' => $shoppingResults,
            'advertiser_count' => $advertiserCount,
        ];
    }

    /**
     * Resolve DataForSEO location payload from geo argument.
     *
     * @param  array<int, string>|string  $geo
     * @return array<string, mixed>
     */
    protected function resolveLocationPayload(array|string $geo): array
    {
        $target = is_array($geo) ? (reset($geo) ?: 'ca') : $geo;
        $target = strtolower(trim((string) $target));

        if (isset(self::GEO_LOCATION_MAP[$target])) {
            return ['location_code' => self::GEO_LOCATION_MAP[$target]];
        }

        if (is_numeric($target)) {
            return ['location_code' => (int) $target];
        }

        if (strlen($target) > 2) {
            return ['location_name' => ucfirst($target)];
        }

        // Fallback to configured location code or Canada default
        $configuredCode = config('watching.dataforseo.location_code');
        if (! empty($configuredCode)) {
            return ['location_code' => (int) $configuredCode];
        }

        $configuredName = config('watching.dataforseo.location_name');
        if (! empty($configuredName)) {
            return ['location_name' => (string) $configuredName];
        }

        return ['location_code' => self::GEO_LOCATION_MAP['ca']];
    }

    /**
     * Normalize time range string into DataForSEO compatible time_range value.
     */
    protected function normalizeTimeRange(string $timeRange): string
    {
        $normalized = strtolower(trim($timeRange));

        return match ($normalized) {
            'today 3-m', 'today 3-months', '90d', 'past_90_days' => 'past_90_days',
            'today 1-m', 'today 1-month', '30d', 'past_30_days' => 'past_30_days',
            'now 7-d', '7d', 'past_7_days' => 'past_7_days',
            'today 12-m', '12m', 'past_12_months' => 'past_12_months',
            default => 'past_90_days',
        };
    }
}
