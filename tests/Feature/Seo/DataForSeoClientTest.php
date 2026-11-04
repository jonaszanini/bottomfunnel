<?php

namespace Tests\Feature\Seo;

use App\Enums\NegativeKeywordSource;
use App\Enums\TrendsTrend;
use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\Seo\DataForSeoClient;
use App\Services\Seo\SeoClientContract;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSeoClientTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        Config::set('watching.seo.provider', 'dataforseo');
        Config::set('watching.dataforseo.login', 'test_dfs_login');
        Config::set('watching.dataforseo.password', 'test_dfs_secret_pass_123');
        Config::set('watching.dataforseo.location_name', 'Canada');
        Config::set('watching.dataforseo.language_code', 'en');
        Config::set('watching.dataforseo.cache_ttl_hours', 12);
    }

    public function test_google_trends_success_returns_normalized_output(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'version' => '0.1.20261009',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'id' => '0101-dfs-trends-task',
                        'status_code' => 20000,
                        'status_message' => 'Ok.',
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'google_trends_graph',
                                        'data' => [
                                            ['timestamp' => 1720000000, 'values' => [20]],
                                            ['timestamp' => 1720600000, 'values' => [25]],
                                            ['timestamp' => 1721200000, 'values' => [30]],
                                            ['timestamp' => 1721800000, 'values' => [35]],
                                            ['timestamp' => 1722400000, 'values' => [40]],
                                            ['timestamp' => 1723000000, 'values' => [45]],
                                            ['timestamp' => 1723600000, 'values' => [50]],
                                            ['timestamp' => 1724200000, 'values' => [55]],
                                            ['timestamp' => 1724800000, 'values' => [60]],
                                            ['timestamp' => 1725400000, 'values' => [95]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $result = $client->googleTrends(['Fimavyn Blood Sugar Formula'], 'ca', 'past_90_days');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('trends_7d', $result);
        $this->assertArrayHasKey('trends_30d', $result);
        $this->assertArrayHasKey('trends_90d', $result);
        $this->assertArrayHasKey('trends_trend', $result);
        $this->assertArrayHasKey('trends_peak', $result);
        $this->assertArrayHasKey('trends_peak_index', $result);
        $this->assertArrayHasKey('trends_total_points', $result);
        $this->assertArrayHasKey('is_dead_post_peak', $result);

        $this->assertEquals(95.0, $result['trends_7d']);
        $this->assertEquals(95.0, $result['trends_peak']);
        $this->assertEquals(10, $result['trends_total_points']);
        $this->assertEquals(TrendsTrend::Rising->value, $result['trends_trend']);
        $this->assertFalse($result['is_dead_post_peak']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'keywords_data/google_trends/explore/live')
                && $request['0']['keywords'][0] === 'Fimavyn Blood Sugar Formula'
                && $request['0']['location_code'] === 2124
                && $request['0']['time_range'] === 'past_90_days';
        });
    }

    public function test_google_trends_dead_post_peak_classification(): void
    {
        // 10 data points: peak = 90 at index 1 (< 50% of 10), recent points collapsed to 5
        Http::fake([
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'google_trends_graph',
                                        'data' => [
                                            ['values' => [30]],
                                            ['values' => [90]], // peak
                                            ['values' => [40]],
                                            ['values' => [20]],
                                            ['values' => [15]],
                                            ['values' => [10]],
                                            ['values' => [8]],
                                            ['values' => [6]],
                                            ['values' => [5]],
                                            ['values' => [5]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $result = $client->googleTrends(['Keto Collapse'], 'ca', 'past_90_days');

        $this->assertTrue($result['is_dead_post_peak']);
        $this->assertEquals(TrendsTrend::DeadPostPeak->value, $result['trends_trend']);
        $this->assertEquals(90.0, $result['trends_peak']);
    }

    public function test_google_serp_success_returns_normalized_output(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'version' => '0.1.20261009',
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'paid',
                                        'title' => 'Official Fimavyn Drops - Buy Discount Formula',
                                        'description' => 'Save up to 70% off retail pricing on official bottles.',
                                        'url' => 'https://www.fimavyn-official.com/special-deal',
                                        'domain' => 'fimavyn-official.com',
                                    ],
                                    [
                                        'type' => 'paid',
                                        'title' => 'Sugar Balance Formula Reviews - Save $300',
                                        'description' => 'Order direct with 60-day money back guarantee.',
                                        'url' => 'https://sugarbalance.org/order',
                                        'domain' => 'sugarbalance.org',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'title' => 'Fimavyn Blood Sugar Formula Review: Is It Legit?',
                                        'description' => 'Detailed ingredients review and official price breakdown ($69 per bottle).',
                                        'url' => 'https://www.healthline-review.com/fimavyn',
                                        'domain' => 'healthline-review.com',
                                    ],
                                    [
                                        'type' => 'organic',
                                        'title' => 'Fimavyn vs Sugar Defender Comparison',
                                        'description' => 'How does Fimavyn compare against Sugar Defender capsules.',
                                        'url' => 'https://supplements-guide.com/vs',
                                        'domain' => 'supplements-guide.com',
                                    ],
                                    [
                                        'type' => 'related_searches',
                                        'items' => [
                                            'fimavyn ingredients',
                                            'fimavyn vs sugar defender',
                                            'buy fimavyn in canada',
                                        ],
                                    ],
                                    [
                                        'type' => 'images',
                                        'items' => [
                                            [
                                                'alt' => 'Fimavyn Blood Sugar Formula bottle',
                                                'url' => 'https://fimavyn-official.com/bottle.jpg',
                                                'image_url' => 'https://cdn.dataforseo.com/thumb1.jpg',
                                            ],
                                        ],
                                    ],
                                    [
                                        'type' => 'shopping',
                                        'items' => [
                                            [
                                                'title' => 'Fimavyn 3-pack bundle',
                                                'price' => 177.0,
                                                'domain' => 'fimavyn.com',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $result = $client->googleSerp('Fimavyn Blood Sugar Formula', 'ca');

        $this->assertIsArray($result);
        $this->assertEquals(2, $result['advertiser_count']);
        $this->assertCount(2, $result['ads']);
        $this->assertCount(2, $result['organic_results']);
        $this->assertCount(2, $result['organic_titles']);
        $this->assertCount(2, $result['organic_domains']);
        $this->assertContains('fimavyn-official.com', $result['ad_domains']);
        $this->assertContains('healthline-review.com', $result['organic_domains']);
        $this->assertContains('fimavyn ingredients', $result['related_searches']);
        $this->assertContains('fimavyn vs sugar defender', $result['related_searches']);
        $this->assertCount(1, $result['inline_images']);
        $this->assertEquals('Fimavyn Blood Sugar Formula bottle', $result['inline_images'][0]['title']);
        $this->assertCount(1, $result['shopping_results']);
    }

    public function test_api_error_returns_graceful_empty_result(): void
    {
        Http::fake([
            'https://api.dataforseo.com/*' => Http::response([
                'status_code' => 40002,
                'status_message' => 'Invalid Field: keyword',
            ], 400),
        ]);

        $client = app(DataForSeoClient::class);
        $trends = $client->googleTrends(['Error Keyword'], 'ca');
        $serp = $client->googleSerp('Error Keyword', 'ca');

        $this->assertEmpty($trends);
        $this->assertEmpty($serp);

        // Parsing directly returns default zeros
        $parsedTrends = $client->parseTrendsResponse([]);
        $this->assertEquals(0.0, $parsedTrends['trends_7d']);
        $this->assertEquals(TrendsTrend::Unknown->value, $parsedTrends['trends_trend']);
    }

    public function test_api_rate_limit_429_returns_graceful_empty_result(): void
    {
        Http::fake([
            'https://api.dataforseo.com/*' => Http::response('Rate limit exceeded', 429),
        ]);

        $client = app(DataForSeoClient::class);
        $trends = $client->googleTrends(['Rate Limited'], 'ca');
        $serp = $client->googleSerp('Rate Limited', 'ca');

        $this->assertEmpty($trends);
        $this->assertEmpty($serp);
    }

    public function test_missing_credentials_skips_http_and_returns_empty(): void
    {
        Config::set('watching.dataforseo.login', null);
        Config::set('watching.dataforseo.password', null);
        Config::set('watching.dataforseo.api_key', null);

        Http::fake();

        $client = new DataForSeoClient(login: null, password: null, apiKey: null);
        $product = WatchedProduct::factory()->create(['name' => 'Unconfigured Test Product']);

        $res = $client->fetchProductData($product);

        $this->assertFalse($res['success']);
        $this->assertEquals('No DataForSEO credentials configured', $res['error']);
        Http::assertNothingSent();
    }

    public function test_fetch_product_data_orchestrates_trends_serp_and_bof(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'google_trends_graph',
                                        'data' => [
                                            ['values' => [50]],
                                            ['values' => [60]],
                                            ['values' => [70]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'organic',
                                        'title' => 'Dentavim Official Site - Buy Discount',
                                        'description' => 'Save on official Dentavim bottles.',
                                        'url' => 'https://dentavim.com',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $product = WatchedProduct::factory()->create([
            'name' => 'Dentavim Oral Health',
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 0,
        ]);

        $result = $client->fetchProductData($product, forceFresh: true, isManual: true);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['trends']);
        $this->assertNotEmpty($result['serp']);
        $this->assertIsArray($result['bof']);
        $this->assertTrue($result['bof']['bof_vpn_serp']);
    }

    public function test_caching_layer_prevents_duplicate_http_calls(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'type' => 'google_trends_graph',
                                        'data' => [['values' => [50]]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);

        // First call triggers HTTP
        $res1 = $client->fetchGoogleTrends('Dentavim Caching Test', forceFresh: true);
        $this->assertNotEmpty($res1);

        // Second call within TTL returns cached result without HTTP
        $res2 = $client->fetchGoogleTrends('Dentavim Caching Test', forceFresh: false);
        $this->assertNotEmpty($res2);

        Http::assertSentCount(1);
    }

    public function test_provider_switch_between_dataforseo_and_serpapi(): void
    {
        // 1. When config is dataforseo
        Config::set('watching.seo.provider', 'dataforseo');
        $resolved = app(SeoClientContract::class);
        $this->assertInstanceOf(DataForSeoClient::class, $resolved);

        // 2. When config is serpapi
        Config::set('watching.seo.provider', 'serpapi');
        $resolvedSerp = app(SeoClientContract::class);
        $this->assertInstanceOf(SerpApiClient::class, $resolvedSerp);
    }

    public function test_ui_refresh_now_endpoint_increments_dataforseo_usage(): void
    {
        Config::set('watching.seo.provider', 'dataforseo');

        Http::fake([
            'https://api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'rates' => ['CAD' => 1.38],
            ], 200),
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            ['items' => [['type' => 'google_trends_graph', 'data' => [['values' => [65]]]]]],
                        ],
                    ],
                ],
            ], 200),
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            ['items' => [['type' => 'organic', 'title' => 'Dentavim Buy', 'url' => 'https://dentavim.com']]],
                        ],
                    ],
                ],
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $budget = app(QuotaBudget::class);
        $this->assertEquals(0, $budget->callsUsedThisMonth('dataforseo'));

        $product = WatchedProduct::factory()->create([
            'name' => 'Dentavim Clean Formula',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 100.0,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/refresh-now");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Product data refreshed via DataForSEO. Total calls used this month: 2/250.');

        $product->refresh();
        $this->assertNotNull($product->last_refreshed_at);
        $this->assertEquals(2, $budget->callsUsedThisMonth('dataforseo'));

        $usage = ApiUsage::query()
            ->where('provider', 'dataforseo')
            ->where('period', $budget->currentPeriod())
            ->first();

        $this->assertNotNull($usage);
        $this->assertEquals(2, $usage->calls);
    }

    public function test_artisan_refresh_command_with_dataforseo_provider(): void
    {
        Config::set('watching.seo.provider', 'dataforseo');

        Http::fake([
            'https://api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'rates' => ['CAD' => 1.38],
            ], 200),
            'https://api.dataforseo.com/v3/keywords_data/google_trends/explore/live' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            ['items' => [['type' => 'google_trends_graph', 'data' => [['values' => [75]]]]]],
                        ],
                    ],
                ],
            ], 200),
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'status_code' => 20000,
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    ['type' => 'paid', 'title' => 'Audifort Order Now', 'url' => 'https://audifort.com'],
                                    ['type' => 'organic', 'title' => 'Audifort Review Price $69', 'url' => 'https://audifort-review.com'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $product = WatchedProduct::factory()->create([
            'name' => 'Audifort Tinnitus Drops',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 120.0,
        ]);

        $this->artisan("watching:refresh --product={$product->id}")
            ->expectsOutputToContain('Running budgeted DataForSEO calls...')
            ->assertSuccessful();

        $product->refresh();
        $this->assertNotNull($product->last_refreshed_at);
        $this->assertEquals(75.0, (float) $product->trends_7d);
        $this->assertEquals(1, $product->advertiser_count);
    }

    public function test_negative_keyword_generation_uses_dataforseo_serp_data(): void
    {
        $generator = app(NegativeKeywordGenerator::class);
        $product = WatchedProduct::factory()->create([
            'name' => 'Dentavim Teeth Support',
            'status' => WatchedProductStatus::Watch,
        ]);

        $dfsSerpData = [
            'related_searches' => [
                'dentavim vs prodentim',
                'dentavim ingredients',
                'unrelated intruder query',
            ],
            'organic_titles' => [
                'Dentavim Reviews',
            ],
            'organic_domains' => [
                'healthnews.org',
            ],
            'organic_results' => [
                ['title' => 'Dentavim Reviews', 'snippet' => '', 'link' => 'https://healthnews.org/dentavim'],
            ],
            'ad_titles' => [],
            'ad_domains' => [],
            'ads' => [],
            'inline_images' => [],
            'shopping_results' => [],
            'advertiser_count' => 0,
        ];

        $inserted = $generator->generateCampaign($product, $dfsSerpData, allowExternalHttp: false);

        $this->assertGreaterThan(0, $inserted);
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'prodentim',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'unrelated intruder query',
            'source' => NegativeKeywordSource::SerpIntruder->value,
        ]);
    }

    public function test_bof_triad_evaluation_with_dataforseo_serp_payload(): void
    {
        $bofChecker = app(BofChecker::class);
        $product = WatchedProduct::factory()->create([
            'name' => 'ProDentim Chewing Formula',
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 0,
            'bof_translation' => false,
        ]);

        $dfsSerp = [
            'ads' => [
                ['title' => 'Buy ProDentim Official - 50% Off Today', 'snippet' => 'Order now', 'link' => 'https://prodentim.com'],
            ],
            'ad_titles' => ['Buy ProDentim Official - 50% Off Today'],
            'organic_results' => [
                ['title' => 'ProDentim Reviews 2026: Does It Work?', 'snippet' => 'Pricing details', 'link' => 'https://review.com'],
            ],
            'inline_images' => [
                ['title' => 'ProDentim bottle image', 'source' => 'prodentim.com'],
            ],
            'shopping_results' => [],
            'advertiser_count' => 1,
        ];

        $bof = $bofChecker->check($product, $dfsSerp);

        $this->assertTrue($bof['bof_vpn_serp']);
        $this->assertGreaterThanOrEqual(90, $bof['bof_image_match_pct']);
        $this->assertTrue($bof['bof_translation']);
    }

    public function test_user_data_success_returns_normalized_balance_and_statistics(): void
    {
        Cache::forget('dataforseo_user_data');

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20230623',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'id' => '0101-user-data-task',
                        'status_code' => 20000,
                        'result' => [
                            [
                                'login' => 'test_dfs_login',
                                'timezone' => 'UTC',
                                'money' => [
                                    'total' => 100.0,
                                    'balance' => 12.45,
                                ],
                                'limits' => [
                                    'day' => ['limit' => 1000, 'spent' => 5],
                                ],
                                'statistics' => [
                                    'day' => [
                                        'v3' => [
                                            'cost' => 0.45,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);
        $result = $client->userData();

        $this->assertIsArray($result);
        $this->assertEquals(12.45, $result['balance']);
        $this->assertEquals(100.0, $result['total_deposited']);
        $this->assertEquals(87.55, $result['total_spent']);
        $this->assertEquals(0.45, $result['today_spend']);
        $this->assertNotNull($result['limits']);
        $this->assertNotNull($result['statistics']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'appendix/user_data')
                && $request->method() === 'GET';
        });
    }

    public function test_user_data_caches_result_and_prevents_duplicate_calls(): void
    {
        Cache::forget('dataforseo_user_data');

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20230623',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'result' => [
                            [
                                'money' => ['balance' => 25.0, 'total' => 50.0],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(DataForSeoClient::class);

        // First call triggers HTTP request
        $res1 = $client->userData();
        $this->assertEquals(25.0, $res1['balance']);

        // Second call should come from cache
        $res2 = $client->userData();
        $this->assertEquals(25.0, $res2['balance']);

        Http::assertSentCount(1);

        // Third call with forceFresh should bypass cache
        $res3 = $client->userData(forceFresh: true);
        $this->assertEquals(25.0, $res3['balance']);

        Http::assertSentCount(2);
    }

    public function test_user_data_handles_errors_gracefully(): void
    {
        Cache::forget('dataforseo_user_data');

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'status_code' => 40001,
                'status_message' => 'Internal server error',
            ], 500),
        ]);

        $client = app(DataForSeoClient::class);
        $result = $client->userData(forceFresh: true);

        $this->assertIsArray($result);
        $this->assertNull($result['balance']);
        $this->assertNull($result['total_deposited']);
        $this->assertNull($result['statistics']);
        $this->assertNull($result['limits']);
    }

    public function test_user_data_returns_nulls_when_not_configured(): void
    {
        Cache::forget('dataforseo_user_data');
        Http::fake();

        $client = new DataForSeoClient(login: '', password: '', apiKey: '');
        $result = $client->userData(forceFresh: true);

        $this->assertNull($result['balance']);
        $this->assertNull($result['total_deposited']);
        Http::assertNothingSent();
    }
}
