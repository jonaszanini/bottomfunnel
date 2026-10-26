<?php

namespace Tests\Feature;

use App\Enums\WatchedProductStatus;
use App\Models\GoogleAdsAccount;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleAdsQuotaException;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordPlannerSearchVolumeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => 'test-dev-token',
            'flowhub.google_ads.version' => 'v26',
            'flowhub.keyword_volume.geos' => [
                'US' => 2840,
                'CA' => 2124,
                'GB' => 2826,
                'AU' => 2036,
            ],
            'flowhub.keyword_volume.default_geos' => ['US', 'CA'],
            'flowhub.keyword_volume.language' => 'languageConstants/1000',
        ]);

        $this->user = User::factory()->create([
            'username' => 'operator',
        ]);

        $this->account = GoogleAdsAccount::query()->create([
            'customer_id' => '1234567890',
            'name' => 'Keyword Volume Ads Account',
            'email' => 'operator@flowhub.com',
            'refresh_token' => 'mock-refresh-token',
            'status' => 'linked',
            'is_default' => true,
        ]);
    }

    public function test_client_parses_normal_historical_metrics_response(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics' => Http::response([
                'results' => [
                    [
                        'text' => 'Prostate Flux',
                        'keywordMetrics' => [
                            'avgMonthlySearches' => '4400',
                            'competition' => 'MEDIUM',
                            'competitionIndex' => 52,
                            'lowTopOfPageBidMicros' => '1250000',
                            'highTopOfPageBidMicros' => '4500000',
                            'monthlySearchVolumes' => [
                                ['year' => '2026', 'month' => 'SEPTEMBER', 'monthlySearches' => '4200'],
                                ['year' => '2026', 'month' => 'AUGUST', 'monthlySearches' => '4600'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $results = $client->keywordHistoricalMetrics($this->account, ['Prostate Flux'], [2840]);

        $this->assertArrayHasKey('prostate flux', $results);
        $metric = $results['prostate flux'];

        $this->assertEquals('Prostate Flux', $metric['keyword']);
        $this->assertSame(4400, $metric['avg_monthly_searches']);
        $this->assertEquals('MEDIUM', $metric['competition']);
        $this->assertSame(52, $metric['competition_index']);
        $this->assertSame(1250000, $metric['low_bid_micros']);
        $this->assertSame(4500000, $metric['high_bid_micros']);
        $this->assertFalse($metric['is_range']);
        $this->assertCount(2, $metric['monthly']);
        $this->assertEquals(9, $metric['monthly'][0]['month']);
    }

    public function test_client_stores_null_when_avg_monthly_searches_is_missing(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics' => Http::response([
                'results' => [
                    [
                        'text' => 'Unknown Brand XYZ',
                        'keywordMetrics' => [
                            // avgMonthlySearches missing entirely
                            'competition' => 'UNSPECIFIED',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $results = $client->keywordHistoricalMetrics($this->account, ['Unknown Brand XYZ'], [2840]);

        $this->assertArrayHasKey('unknown brand xyz', $results);
        $this->assertNull($results['unknown brand xyz']['avg_monthly_searches']);
        $this->assertFalse($results['unknown brand xyz']['is_range']);
    }

    public function test_client_detects_range_buckets_and_sets_is_range_true(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics' => Http::response([
                'results' => [
                    [
                        'text' => 'Range Brand Product',
                        'keywordMetrics' => [
                            'avgMonthlySearches' => '500',
                            'searchVolume' => [
                                'min' => '100',
                                'max' => '1000',
                            ],
                            'monthlySearchVolumes' => [
                                ['year' => '2026', 'month' => 'SEPTEMBER', 'monthlySearches' => '1000'],
                                ['year' => '2026', 'month' => 'AUGUST', 'monthlySearches' => '1000'],
                                ['year' => '2026', 'month' => 'JULY', 'monthlySearches' => '1000'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $results = $client->keywordHistoricalMetrics($this->account, ['Range Brand Product'], [2840]);

        $metric = $results['range brand product'];
        $this->assertTrue($metric['is_range']);
        $this->assertSame(100, $metric['min']);
        $this->assertSame(1000, $metric['max']);
    }

    public function test_client_maps_close_variants_back_to_requested_keyword(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics' => Http::response([
                'results' => [
                    [
                        'text' => 'prostate flux supplement',
                        'closeVariants' => [
                            'Prostate Flux',
                        ],
                        'keywordMetrics' => [
                            'avgMonthlySearches' => '1800',
                            'competition' => 'LOW',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $results = $client->keywordHistoricalMetrics($this->account, ['Prostate Flux'], [2840]);

        $this->assertArrayHasKey('prostate flux', $results);
        $this->assertSame(1800, $results['prostate flux']['avg_monthly_searches']);
    }

    public function test_client_throws_google_ads_quota_exception_on_resource_exhausted(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted (e.g. check quota).',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $this->expectException(GoogleAdsQuotaException::class);

        $client = app(GoogleAdsClient::class);
        $client->keywordHistoricalMetrics($this->account, ['Some Keyword'], [2840]);
    }

    public function test_geo_resolution_uses_allowed_geos_and_falls_back_to_defaults(): void
    {
        $productWithGeos = WatchedProduct::create([
            'name' => 'Custom Geo Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US', 'AU', 'FR'], // FR is not in config geos, US and AU are
        ]);

        $productDefaultGeos = WatchedProduct::create([
            'name' => 'Default Geo Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => [],
        ]);

        $this->assertEquals(['US', 'AU'], $productWithGeos->searchVolumeGeos());
        $this->assertEquals(['US', 'CA'], $productDefaultGeos->searchVolumeGeos());
    }

    public function test_command_dry_run_writes_nothing_to_database(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Dry Run Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US'],
        ]);

        Http::fake();

        $this->artisan('watching:refresh-volume', ['--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();

        $fresh = $product->fresh();
        $this->assertNull($fresh->search_volume_total);
        $this->assertNull($fresh->search_volume_checked_at);
        $this->assertNull($fresh->search_volume_by_geo);
    }

    public function test_command_updates_product_fields_and_skips_rejected(): void
    {
        $activeProduct = WatchedProduct::create([
            'name' => 'Active Product 1',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US', 'CA'],
        ]);

        $rejectedProduct = WatchedProduct::create([
            'name' => 'Rejected Product 2',
            'status' => WatchedProductStatus::Rejected,
            'allowed_geos' => ['US'],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics*' => function ($request) {
                $body = json_decode($request->body(), true);
                $geo = $body['geoTargetConstants'][0] ?? '';

                // US=2840 (800 searches), CA=2124 (400 searches)
                $searches = str_contains($geo, '2840') ? '800' : '400';

                return Http::response([
                    'results' => [
                        [
                            'text' => 'Active Product 1',
                            'keywordMetrics' => [
                                'avgMonthlySearches' => $searches,
                                'competition' => 'LOW',
                                'competitionIndex' => 15,
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        $this->artisan('watching:refresh-volume')
            ->assertExitCode(0);

        $activeFresh = $activeProduct->fresh();
        $rejectedFresh = $rejectedProduct->fresh();

        $this->assertSame(1200, $activeFresh->search_volume_total);
        $this->assertFalse($activeFresh->search_volume_is_range);
        $this->assertNotNull($activeFresh->search_volume_checked_at);
        $this->assertArrayHasKey('US', $activeFresh->search_volume_by_geo);
        $this->assertArrayHasKey('CA', $activeFresh->search_volume_by_geo);
        $this->assertSame(800, $activeFresh->search_volume_by_geo['US']['avg_monthly_searches']);
        $this->assertSame(400, $activeFresh->search_volume_by_geo['CA']['avg_monthly_searches']);

        // Rejected was untouched
        $this->assertNull($rejectedFresh->search_volume_total);
        $this->assertNull($rejectedFresh->search_volume_checked_at);
    }

    public function test_command_respects_stale_days(): void
    {
        $recentlyChecked = WatchedProduct::create([
            'name' => 'Recently Checked Product',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 500,
            'search_volume_checked_at' => Carbon::now()->subDays(2),
        ]);

        $staleProduct = WatchedProduct::create([
            'name' => 'Stale Product',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 200,
            'search_volume_checked_at' => Carbon::now()->subDays(10),
            'allowed_geos' => ['US'],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics*' => Http::response([
                'results' => [
                    [
                        'text' => 'Stale Product',
                        'keywordMetrics' => [
                            'avgMonthlySearches' => '3500',
                            'competition' => 'HIGH',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('watching:refresh-volume', ['--stale-days' => 7])
            ->assertExitCode(0);

        $this->assertSame(500, $recentlyChecked->fresh()->search_volume_total);
        $this->assertSame(3500, $staleProduct->fresh()->search_volume_total);
    }

    public function test_command_stores_api_error_per_product_without_aborting_run(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Error Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US'],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid customer id.',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        $this->artisan('watching:refresh-volume')
            ->assertExitCode(1);

        $fresh = $product->fresh();
        $this->assertNotNull($fresh->search_volume_error);
        $this->assertStringContainsString('Invalid customer id', $fresh->search_volume_error);
        $this->assertNull($fresh->search_volume_checked_at);
        $this->assertNotNull($fresh->search_volume_error_at);
    }

    public function test_controller_filters_volume_correctly(): void
    {
        $highVol = WatchedProduct::create([
            'name' => 'High Volume 15k',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 15000,
        ]);

        $midVol = WatchedProduct::create([
            'name' => 'Mid Volume 2k',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 2000,
        ]);

        $lowVol = WatchedProduct::create([
            'name' => 'Low Volume 400',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 400,
        ]);

        $unknownVol = WatchedProduct::create([
            'name' => 'Unknown Volume',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => null,
        ]);

        // Filter 1k+
        $res1k = $this->actingAs($this->user)->get('/watching-products?volume=1k');
        $res1k->assertOk();
        $products1k = collect($res1k->viewData('page')['props']['products']);
        $names1k = $products1k->pluck('name')->all();

        $this->assertContains('High Volume 15k', $names1k);
        $this->assertContains('Mid Volume 2k', $names1k);
        $this->assertNotContains('Low Volume 400', $names1k);
        $this->assertNotContains('Unknown Volume', $names1k);

        // Filter 10k+
        $res10k = $this->actingAs($this->user)->get('/watching-products?volume=10k');
        $res10k->assertOk();
        $products10k = collect($res10k->viewData('page')['props']['products']);
        $names10k = $products10k->pluck('name')->all();

        $this->assertContains('High Volume 15k', $names10k);
        $this->assertNotContains('Mid Volume 2k', $names10k);

        // Filter unknown
        $resUnknown = $this->actingAs($this->user)->get('/watching-products?volume=unknown');
        $resUnknown->assertOk();
        $productsUnknown = collect($resUnknown->viewData('page')['props']['products']);
        $namesUnknown = $productsUnknown->pluck('name')->all();

        $this->assertContains('Unknown Volume', $namesUnknown);
        $this->assertNotContains('High Volume 15k', $namesUnknown);
    }

    public function test_controller_sorts_by_search_volume_total(): void
    {
        WatchedProduct::create([
            'name' => 'Product 100',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 100,
        ]);

        WatchedProduct::create([
            'name' => 'Product 5000',
            'status' => WatchedProductStatus::Watch,
            'search_volume_total' => 5000,
        ]);

        $res = $this->actingAs($this->user)->get('/watching-products?sort=search_volume_total&direction=desc');
        $res->assertOk();

        $products = collect($res->viewData('page')['props']['products']);
        $this->assertEquals('Product 5000', $products->first()['name']);
    }

    public function test_single_product_synchronous_refresh_route(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Sync Refresh Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US'],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics*' => Http::response([
                'results' => [
                    [
                        'text' => 'Sync Refresh Product',
                        'keywordMetrics' => [
                            'avgMonthlySearches' => '7200',
                            'competition' => 'LOW',
                            'competitionIndex' => 12,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/refresh-volume");

        $response->assertRedirect();
        $response->assertSessionHas('toast', 'Search volume refreshed successfully.');

        $fresh = $product->fresh();
        $this->assertSame(7200, $fresh->search_volume_total);
        $this->assertNotNull($fresh->search_volume_checked_at);
        $this->assertArrayHasKey('US', $fresh->search_volume_by_geo);
    }

    private function fakeTokenAnd(callable|PromiseInterface $metrics): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock-access-token', 'expires_in' => 3600], 200),
            'https://googleads.googleapis.com/v26/customers/1234567890:generateKeywordHistoricalMetrics*' => $metrics,
        ]);
    }

    private function errorResponse(int $code = 400, string $status = 'INVALID_ARGUMENT', string $message = 'Bad geo.')
    {
        return Http::response(['error' => ['code' => $code, 'message' => $message, 'status' => $status]], $code);
    }

    public function test_command_exits_failure_when_every_product_errors(): void
    {
        WatchedProduct::create(['name' => 'Fail A', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US']]);
        WatchedProduct::create(['name' => 'Fail B', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['CA']]);

        $this->fakeTokenAnd($this->errorResponse());

        $this->artisan('watching:refresh-volume')->assertExitCode(1);
    }

    public function test_partial_geo_failure_keeps_successful_geo(): void
    {
        $product = WatchedProduct::create(['name' => 'Partial Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US', 'CA']]);

        $this->fakeTokenAnd(function ($request) {
            $body = json_decode($request->body(), true);
            $geo = $body['geoTargetConstants'][0] ?? '';
            if (str_contains($geo, '2124')) {
                return $this->errorResponse(400, 'INVALID_ARGUMENT', 'CA failed.');
            }

            return Http::response(['results' => [['text' => 'Partial Product', 'keywordMetrics' => ['avgMonthlySearches' => '900', 'competition' => 'LOW']]]], 200);
        });

        $this->artisan('watching:refresh-volume');

        $fresh = $product->fresh();
        $this->assertArrayHasKey('US', $fresh->search_volume_by_geo);
        $this->assertArrayNotHasKey('CA', $fresh->search_volume_by_geo);
        $this->assertSame(900, $fresh->search_volume_total);
        $this->assertStringContainsString('CA', $fresh->search_volume_error);
        $this->assertStringNotContainsString('(US)', $fresh->search_volume_error);
        $this->assertNotNull($fresh->search_volume_error_at);
    }

    public function test_quota_exception_mid_loop_persists_already_fetched_geos(): void
    {
        $product = WatchedProduct::create(['name' => 'Quota Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US', 'CA']]);

        $this->fakeTokenAnd(function ($request) {
            $body = json_decode($request->body(), true);
            $geo = $body['geoTargetConstants'][0] ?? '';
            if (str_contains($geo, '2124')) {
                return $this->errorResponse(429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).');
            }

            return Http::response(['results' => [['text' => 'Quota Product', 'keywordMetrics' => ['avgMonthlySearches' => '300', 'competition' => 'LOW']]]], 200);
        });

        $this->artisan('watching:refresh-volume')->assertExitCode(1);

        $fresh = $product->fresh();
        $this->assertSame(300, $fresh->search_volume_by_geo['US']['avg_monthly_searches']);
        $this->assertNotNull($fresh->search_volume_error);
    }

    public function test_quota_break_records_error_on_products_in_unreached_geos(): void
    {
        $usProduct = WatchedProduct::create(['name' => 'US Only Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US']]);
        $gbProduct = WatchedProduct::create(['name' => 'GB Only Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['GB']]);

        $this->fakeTokenAnd($this->errorResponse(429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).'));

        $this->artisan('watching:refresh-volume')->assertExitCode(1);

        $this->assertNotNull($usProduct->fresh()->search_volume_error);
        $this->assertNotNull($usProduct->fresh()->search_volume_error_at);
        $gb = $gbProduct->fresh();
        $this->assertNotNull($gb->search_volume_error);
        $this->assertStringContainsString('quota', $gb->search_volume_error);
        $this->assertNotNull($gb->search_volume_error_at);
    }

    public function test_existing_data_survives_error_and_checked_at_unchanged(): void
    {
        $checkedAt = Carbon::now()->subDays(10)->startOfSecond();
        $product = WatchedProduct::create([
            'name' => 'Existing Data Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US'],
            'search_volume_total' => 1500,
            'search_volume_by_geo' => ['US' => ['keyword' => 'Existing Data Product', 'avg_monthly_searches' => 1500, 'is_range' => false]],
            'search_volume_checked_at' => $checkedAt,
        ]);

        $this->fakeTokenAnd($this->errorResponse());

        $this->artisan('watching:refresh-volume');

        $fresh = $product->fresh();
        $this->assertSame(1500, $fresh->search_volume_total);
        $this->assertSame(1500, $fresh->search_volume_by_geo['US']['avg_monthly_searches']);
        $this->assertTrue($checkedAt->equalTo($fresh->search_volume_checked_at));
        $this->assertNotNull($fresh->search_volume_error_at);
        $this->assertNotNull($fresh->search_volume_error);
    }

    public function test_errored_product_is_retried_within_stale_window(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Retry Product',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['US'],
            'search_volume_checked_at' => Carbon::now()->subDay(),
            'search_volume_error' => 'Previous failure',
            'search_volume_error_at' => Carbon::now()->subHour(),
        ]);

        $this->fakeTokenAnd(Http::response(['results' => [['text' => 'Retry Product', 'keywordMetrics' => ['avgMonthlySearches' => '4200', 'competition' => 'LOW']]]], 200));

        $this->artisan('watching:refresh-volume', ['--stale-days' => 7])->assertExitCode(0);

        $fresh = $product->fresh();
        $this->assertSame(4200, $fresh->search_volume_total);
        $this->assertNull($fresh->search_volume_error);
        $this->assertNull($fresh->search_volume_error_at);
    }

    public function test_refresh_route_flashes_error_on_failure(): void
    {
        $product = WatchedProduct::create(['name' => 'Route Error Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US']]);

        $this->fakeTokenAnd($this->errorResponse());

        $response = $this->actingAs($this->user)->post("/watching-products/{$product->id}/refresh-volume");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('toast');
    }

    public function test_refresh_route_quota_exception_flashes_error_not_500(): void
    {
        $product = WatchedProduct::create(['name' => 'Route Quota Product', 'status' => WatchedProductStatus::Watch, 'allowed_geos' => ['US']]);

        $this->fakeTokenAnd($this->errorResponse(429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).'));

        $response = $this->actingAs($this->user)->post("/watching-products/{$product->id}/refresh-volume");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_schedule_is_registered_in_console(): void
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events());

        $hasRefreshVolume = $events->contains(function ($event) {
            return str_contains($event->command ?? '', 'watching:refresh-volume');
        });

        $this->assertTrue($hasRefreshVolume, 'Schedule event watching:refresh-volume is registered');
    }
}
