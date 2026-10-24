<?php

namespace Tests\Feature\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\FxRate;
use App\Models\ProductScoreHistory;
use App\Models\Track;
use App\Models\WatchedProduct;
use App\Services\Fx\BankOfCanadaFx;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuotaAndScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Scenario 1: Atomic Quota Enforcement - Below monthly cap.
     * Seed ApiUsage below monthly cap and assert QuotaBudget allows refreshes for standard WatchedProducts.
     */
    public function test_quota_budget_allows_refreshes_for_standard_products_below_monthly_cap(): void
    {
        $budget = app(QuotaBudget::class);
        $period = $budget->currentPeriod();
        $monthlyCap = (int) config('watching.serpapi_monthly_cap', 230);

        // Seed 100 calls (well below 230 cap, ~43.5% usage)
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $period,
            'calls' => 100,
        ]);

        $this->assertSame(100, $budget->callsUsedThisMonth());
        $this->assertTrue($budget->canSpendWeekly(2));
        $this->assertTrue($budget->canSpendManual(2));
        $this->assertFalse($budget->isQuotaHigh());

        // Create standard watch products (refresh_priority = 0, score < 60)
        $standardProduct1 = WatchedProduct::factory()->create([
            'name' => 'Standard Product Alpha',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 0,
            'score' => 45,
            'last_refreshed_at' => now()->subDays(15),
            'bof_homonym' => false,
        ]);

        $standardProduct2 = WatchedProduct::factory()->create([
            'name' => 'Standard Product Beta',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 0,
            'score' => 40,
            'last_refreshed_at' => null,
            'bof_homonym' => false,
        ]);

        $selected = $budget->selectProductsForWeeklyRefresh();
        $selectedIds = $selected->pluck('id')->all();

        $this->assertContains($standardProduct1->id, $selectedIds);
        $this->assertContains($standardProduct2->id, $selectedIds);

        // Atomic spend deduction under distributed lock
        $spent = $budget->atomicSpend(2, isManual: false);
        $this->assertTrue($spent);
        $this->assertSame(102, $budget->callsUsedThisMonth());
    }

    /**
     * Scenario 1: 85% Throttling in QuotaBudget during RefreshWatchingProducts.
     * Seed ApiUsage at 85% of monthly cap. Run RefreshWatchingProducts and assert that
     * low priority watched products are skipped while high priority or active campaign products are refreshed.
     */
    public function test_quota_budget_85_percent_threshold_throttles_low_priority_products_during_refresh_command(): void
    {
        $budget = app(QuotaBudget::class);
        $period = $budget->currentPeriod();
        $monthlyCap = (int) config('watching.serpapi_monthly_cap', 230);

        // 85% of 230 = 195.5 calls. Seed with 196 calls (>= 85% threshold)
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $period,
            'calls' => 196,
        ]);

        $this->assertTrue($budget->isQuotaHigh());

        // Seed an active campaign in Track model
        Track::query()->create([
            'product_name' => 'Active Campaign Widget',
            'campaign_ids' => ['google-camp-9988'],
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);

        $tenDaysAgo = now()->subDays(10)->startOfMinute();

        // 1. Low priority product (watch status, priority 0, score 35, unlinked from Track)
        $lowPriority = WatchedProduct::factory()->create([
            'name' => 'Low Priority Regular Widget',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 0,
            'score' => 35,
            'last_refreshed_at' => $tenDaysAgo,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        // 2. High priority product via explicit refresh_priority > 0
        $highPriorityExplicit = WatchedProduct::factory()->create([
            'name' => 'High Priority Explicit Widget',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 1,
            'score' => 35,
            'last_refreshed_at' => $tenDaysAgo,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        // 3. Active campaign product (name matches active Track campaign)
        $activeCampaignProduct = WatchedProduct::factory()->create([
            'name' => 'Active Campaign Widget',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 0,
            'score' => 30,
            'last_refreshed_at' => $tenDaysAgo,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        // 4. Test status product (campaign preparation / active testing)
        $testStatusProduct = WatchedProduct::factory()->create([
            'name' => 'Test Status Candidate Widget',
            'status' => WatchedProductStatus::Test,
            'refresh_priority' => 0,
            'score' => 40,
            'last_refreshed_at' => $tenDaysAgo,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        // 5. High potential score product (score >= 60)
        $highScoreProduct = WatchedProduct::factory()->create([
            'name' => 'High Potential Score Widget',
            'status' => WatchedProductStatus::Watch,
            'refresh_priority' => 0,
            'score' => 75,
            'last_refreshed_at' => $tenDaysAgo,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        // Verify selectProductsForWeeklyRefresh strictly filters candidates at 85%+ quota
        $selectedForRefresh = $budget->selectProductsForWeeklyRefresh();
        $selectedIds = $selectedForRefresh->pluck('id')->all();

        $this->assertNotContains($lowPriority->id, $selectedIds, 'Low priority product must be skipped at 85%+ quota.');
        $this->assertContains($highPriorityExplicit->id, $selectedIds, 'Explicit refresh_priority > 0 must be included.');
        $this->assertContains($activeCampaignProduct->id, $selectedIds, 'Active campaign product must be included.');
        $this->assertContains($testStatusProduct->id, $selectedIds, 'Test status product must be included.');
        $this->assertContains($highScoreProduct->id, $selectedIds, 'Score >= 60 product must be included.');

        // Configure SerpApi & fake endpoints
        config(['watching.serpapi_key' => 'test-quota-key']);

        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'interest_over_time' => [
                    'timeline_data' => [
                        ['values' => [['extracted_value' => 60]]],
                        ['values' => [['extracted_value' => 70]]],
                    ],
                ],
                'organic_results' => [
                    ['title' => 'Official Site', 'link' => 'https://example.com'],
                ],
                'ads' => [],
            ], 200),
            'https://api.frankfurter.app/*' => Http::response(['rates' => ['CAD' => 1.38]], 200),
            'https://www.bankofcanada.ca/*' => Http::response('Not found', 404),
        ]);

        // Run the refresh command
        $this->artisan('watching:refresh')->assertSuccessful();

        // Assert low priority product was skipped: last_refreshed_at was NOT refreshed
        $this->assertEquals(
            $tenDaysAgo->toDateTimeString(),
            $lowPriority->fresh()->last_refreshed_at?->toDateTimeString(),
            'Low priority product must not have its last_refreshed_at updated.'
        );

        // Assert high priority, active campaign, test status, and high score products WERE refreshed
        $this->assertTrue(
            $highPriorityExplicit->fresh()->last_refreshed_at->greaterThan($tenDaysAgo),
            'High priority product must be refreshed.'
        );
        $this->assertTrue(
            $activeCampaignProduct->fresh()->last_refreshed_at->greaterThan($tenDaysAgo),
            'Active campaign product must be refreshed.'
        );
        $this->assertTrue(
            $testStatusProduct->fresh()->last_refreshed_at->greaterThan($tenDaysAgo),
            'Test status product must be refreshed.'
        );
        $this->assertTrue(
            $highScoreProduct->fresh()->last_refreshed_at->greaterThan($tenDaysAgo),
            'High score product must be refreshed.'
        );
    }

    /**
     * Scenario 1: Quota Exhausted at 100% of Cap.
     * Seed ApiUsage at 100% of cap and assert SerpApiClient refuses external HTTP requests and returns empty quota-exhausted result.
     */
    public function test_serp_api_client_refuses_external_http_requests_when_quota_exhausted(): void
    {
        $budget = app(QuotaBudget::class);
        $period = $budget->currentPeriod();
        $monthlyCap = (int) config('watching.serpapi_monthly_cap', 230);
        $totalLimit = (int) config('watching.serpapi_total_limit', 250);

        // 1. Seed at automated monthly cap (230)
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $period,
            'calls' => $monthlyCap,
        ]);

        config(['watching.serpapi_key' => 'exhausted-test-key']);
        Http::fake();

        $client = app(SerpApiClient::class);

        // Automated/weekly calls must refuse HTTP and return empty array
        $trendsResult = $client->fetchGoogleTrends('Keto Drops', apiKey: 'exhausted-test-key', isManual: false);
        $this->assertSame([], $trendsResult);

        $serpResult = $client->fetchGoogleSerp('Keto Drops', apiKey: 'exhausted-test-key', isManual: false);
        $this->assertSame([], $serpResult);

        Http::assertNothingSent();

        // 2. Now seed at total hard limit (250)
        ApiUsage::query()->where('period', $period)->update(['calls' => $totalLimit]);

        $this->assertSame($totalLimit, $budget->callsUsedThisMonth());
        $this->assertFalse($budget->canSpendManual(1));
        $this->assertFalse($budget->atomicSpend(1, isManual: true));

        // Manual calls must also refuse external requests
        $trendsManual = $client->fetchGoogleTrends('Keto Drops Manual', apiKey: 'exhausted-test-key', isManual: true);
        $this->assertSame([], $trendsManual);

        $serpManual = $client->fetchGoogleSerp('Keto Drops Manual', apiKey: 'exhausted-test-key', isManual: true);
        $this->assertSame([], $serpManual);

        $product = WatchedProduct::factory()->create([
            'name' => 'Keto Drops Manual',
            'sales_page_url' => null,
            'listing_url' => null,
        ]);

        $productData = $client->fetchProductData($product, forceFresh: true, isManual: true);
        $this->assertFalse($productData['success']);
        $this->assertSame([], $productData['trends']);
        $this->assertSame([], $productData['serp']);
        $this->assertSame('API calls returned empty or failed', $productData['error']);

        Http::assertNothingSent();
    }

    /**
     * Scenario 2: SerpApiClient Response Caching.
     * Call SerpApiClient twice with identical keyword, geo, language, and device parameters within cache TTL window.
     * Assert that Http::assertSentCount(1) holds true and ApiUsage is incremented only once.
     */
    public function test_serp_api_client_response_caching_prevents_duplicate_http_calls_and_quota_spend(): void
    {
        config(['watching.serpapi_key' => 'valid-caching-key']);
        config(['watching.serpapi_cache_ttl_hours' => 12]);

        $budget = app(QuotaBudget::class);
        $client = app(SerpApiClient::class);

        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'interest_over_time' => [
                    'timeline_data' => [
                        ['values' => [['extracted_value' => 50]]],
                        ['values' => [['extracted_value' => 60]]],
                        ['values' => [['extracted_value' => 70]]],
                    ],
                ],
            ], 200),
        ]);

        $initialCalls = $budget->callsUsedThisMonth();

        // First call: executes HTTP request and increments quota
        $firstResult = $client->fetchGoogleTrends(
            query: 'NeuroPeak Pro',
            apiKey: 'valid-caching-key',
            gl: 'ca',
            hl: 'en',
            device: 'desktop',
            forceFresh: false,
            isManual: false
        );

        $this->assertNotEmpty($firstResult);
        Http::assertSentCount(1);
        $this->assertSame($initialCalls + 1, $budget->callsUsedThisMonth());

        // Second call with identical parameters within cache TTL window:
        // Must hit deterministic cache, skip external HTTP request, and not increment ApiUsage!
        $secondResult = $client->fetchGoogleTrends(
            query: 'NeuroPeak Pro',
            apiKey: 'valid-caching-key',
            gl: 'ca',
            hl: 'en',
            device: 'desktop',
            forceFresh: false,
            isManual: false
        );

        $this->assertEquals($firstResult, $secondResult);
        Http::assertSentCount(1);
        $this->assertSame($initialCalls + 1, $budget->callsUsedThisMonth());

        // Third call with whitespace and case variations:
        // Cache key normalizes query/gl/hl/device, preventing duplicate burns
        $thirdResult = $client->fetchGoogleTrends(
            query: '  neuropeak pro  ',
            apiKey: 'valid-caching-key',
            gl: 'CA',
            hl: 'EN',
            device: 'DESKTOP',
            forceFresh: false,
            isManual: false
        );

        $this->assertEquals($firstResult, $thirdResult);
        Http::assertSentCount(1);
        $this->assertSame($initialCalls + 1, $budget->callsUsedThisMonth());
    }

    /**
     * Scenario 3: PotentialScoreService Edge Cases and CAD$ FX Consistency.
     * When SerpApi returns null or 0.00 for CPC, assert the service applies the conservative
     * fallback CPC floor in CAD$ rather than dividing by zero or inflating score to maximum.
     */
    public function test_potential_score_service_applies_conservative_cad_cpc_fallback_when_cpc_is_zero_or_null(): void
    {
        // Seed today's FxRate: 1 USD = 1.38 CAD
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => '1.38000000',
            'source' => 'bank_of_canada',
        ]);

        $scoreService = app(PotentialScoreService::class);
        $fallbackCpcCad = (float) config('watching.thresholds.fallback_cpc_floor_cad', 1.50);

        // Case A: Missing/Null CPC with modest payout
        // Payout = $50.00 USD, CVR = 2% (0.02) -> Revenue per click = $1.00 USD
        // Fallback CPC in CAD = $1.50 CAD -> in USD @ 1.38 CAD/USD = $1.0869 USD
        // Margin per click = $1.00 - $1.0869 = -0.0869 <= 0.0 -> Margin headroom score = 0!
        // (Without fallback, $1.00 / 1.50 * 10 would artificially inflate score to 7/10)
        $productNullCpc = WatchedProduct::factory()->create([
            'payout_amount' => 50.00,
            'payout_currency' => 'USD',
            'brand_cpc_amount' => null,
            'kp_cpc_amount' => 0.00,
            'est_cvr' => 0.02,
        ]);

        $resultNull = $scoreService->score($productNullCpc);

        $this->assertTrue($resultNull['flags']['fallback_cpc_applied'] ?? false);
        $this->assertSame('fallback', $resultNull['flags']['cpc_confidence'] ?? null);
        $this->assertSame($fallbackCpcCad, $resultNull['flags']['fallback_cpc_cad'] ?? null);
        $this->assertSame(0, $resultNull['breakdown']['margin_headroom']);

        // Case B: Explicit 0.00 CPC (auction data returned 0.00)
        $productZeroCpc = WatchedProduct::factory()->create([
            'payout_amount' => 50.00,
            'payout_currency' => 'USD',
            'brand_cpc_amount' => 0.00,
            'kp_cpc_amount' => null,
            'est_cvr' => 0.02,
        ]);

        $resultZero = $scoreService->score($productZeroCpc);

        $this->assertTrue($resultZero['flags']['fallback_cpc_applied'] ?? false);
        $this->assertSame('fallback', $resultZero['flags']['cpc_confidence'] ?? null);
        $this->assertSame(0, $resultZero['breakdown']['margin_headroom']);

        // Case C: Explicit auction CPC with high payout
        // Payout = $150.00 USD, CVR = 0.02 -> Revenue per click = $3.00 USD
        // Brand CPC = $0.40 USD -> Margin per click = $3.00 - $0.40 = $2.60 USD (> $1.50)
        // Margin headroom score = 10 (maximum)
        $productWithCpc = WatchedProduct::factory()->create([
            'payout_amount' => 150.00,
            'payout_currency' => 'USD',
            'brand_cpc_amount' => 0.40,
            'brand_cpc_currency' => 'USD',
            'est_cvr' => 0.02,
        ]);

        $resultWithCpc = $scoreService->score($productWithCpc);

        $this->assertSame('high', $resultWithCpc['flags']['cpc_confidence'] ?? null);
        $this->assertFalse($resultWithCpc['flags']['fallback_cpc_applied'] ?? false);
        $this->assertSame(10, $resultWithCpc['breakdown']['margin_headroom']);
    }

    /**
     * Scenario 3: ProductScoreHistory Auditing.
     * Assert that every scoring run creates an auditable snapshot in ProductScoreHistory.
     */
    public function test_scoring_creates_auditable_snapshot_in_product_score_history(): void
    {
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => '1.38000000',
            'source' => 'bank_of_canada',
        ]);

        $product = WatchedProduct::factory()->create([
            'name' => 'Auditable Product Alpha',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 125.00,
            'payout_currency' => 'USD',
            'page_type' => 'TSL',
            'trends_30d' => 72.50,
            'advertiser_count' => 2,
            'sales_page_url' => null,
            'listing_url' => null,
            'bof_homonym' => false,
        ]);

        config(['watching.serpapi_key' => 'audit-test-key']);

        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'interest_over_time' => [
                    'timeline_data' => [
                        ['values' => [['extracted_value' => 65]]],
                    ],
                ],
                'organic_results' => [],
                'ads' => [
                    ['title' => 'Competitor Ad 1', 'link' => 'https://competitor1.com'],
                    ['title' => 'Competitor Ad 2', 'link' => 'https://competitor2.com'],
                ],
            ], 200),
            'https://api.frankfurter.app/*' => Http::response(['rates' => ['CAD' => 1.38]], 200),
            'https://www.bankofcanada.ca/*' => Http::response('Not found', 404),
        ]);

        // Initial history record created by WatchedProductObserver upon creation
        $initialHistoryCount = ProductScoreHistory::query()->where('product_id', $product->id)->count();
        $this->assertSame(1, $initialHistoryCount, 'WatchedProductObserver creates initial score snapshot upon creation.');

        // Run the refresh command targeting this product
        $this->artisan('watching:refresh', ['--product' => $product->id])->assertSuccessful();

        $this->assertSame(
            $initialHistoryCount + 1,
            ProductScoreHistory::query()->where('product_id', $product->id)->count(),
            'Refresh command must append a new auditable ProductScoreHistory snapshot.'
        );

        $historyRecord = ProductScoreHistory::query()
            ->where('product_id', $product->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($historyRecord, 'Scoring run must create a ProductScoreHistory record.');
        $this->assertSame($product->fresh()->score, $historyRecord->score);

        // Assert breakdown has all components
        $breakdown = $historyRecord->breakdown;
        $this->assertIsArray($breakdown);
        $expectedComponents = [
            'bof_name',
            'demand',
            'payout',
            'margin_headroom',
            'page_type',
            'brand_bidding',
            'geo',
            'competition',
            'freshness',
        ];
        foreach ($expectedComponents as $component) {
            $this->assertArrayHasKey($component, $breakdown);
        }

        // Assert inputs snapshot records exact auditable attributes
        $inputs = $historyRecord->inputs_snapshot;
        $this->assertIsArray($inputs);
        $this->assertSame('Auditable Product Alpha', $inputs['name']);
        $this->assertEquals(125.00, $inputs['payout_amount']);
        $this->assertSame('USD', $inputs['payout_currency']);
        $this->assertSame('TSL', $inputs['page_type']);
        $this->assertEquals($product->fresh()->trends_30d, $inputs['trends_30d']);
        $this->assertSame(2, $inputs['advertiser_count']);
    }

    /**
     * Scenario 3: CAD$ FX Consistency between BankOfCanadaFx and FrankfurterFx.
     * Tests rate caching, cross-currency calculations, and fallbacks.
     */
    public function test_cad_fx_consistency_between_bank_of_canada_and_frankfurter(): void
    {
        // 1. Seed Bank of Canada rate in FxRate
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => '1.38000000',
            'source' => 'bank_of_canada',
        ]);

        $frankfurter = app(FrankfurterFx::class);
        $boc = app(BankOfCanadaFx::class);

        // BankOfCanadaFx rate
        $this->assertSame('1.38000000', $boc->cadPerUnit('USD'));

        // FrankfurterFx uses BankOfCanadaFx for CAD conversions
        $cadToUsdRate = $frankfurter->getRate('CAD', 'USD');
        $this->assertEqualsWithDelta(1.0 / 1.38, $cadToUsdRate, 0.0001);

        // Verify Money model uses the rate accurately
        $fallbackMoneyCad = Money::make(1.50, 'CAD');
        $this->assertSame(1.09, $fallbackMoneyCad->toUsd($frankfurter));

        // 2. Fallback when Bank of Canada has network failure:
        // FrankfurterFx fetches directly from Frankfurter API and saves with source = 'frankfurter'
        Http::fake([
            'https://www.bankofcanada.ca/*' => Http::response('Valet 500 error', 500),
            'https://api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'from' => 'EUR',
                'to' => 'CAD',
                'rates' => ['CAD' => 1.4850],
            ], 200),
        ]);

        $eurCadRate = $boc->cadPerUnit('EUR');
        $this->assertSame('1.48500000', $eurCadRate);

        $savedRate = FxRate::query()
            ->where('currency', 'EUR')
            ->whereDate('rate_date', today())
            ->first();

        $this->assertNotNull($savedRate);
        $this->assertSame('frankfurter', $savedRate->source);
        $this->assertSame('1.48500000', $savedRate->cad_per_unit);
    }

    /**
     * Scenario 4: Bulk RankService Accuracy.
     * Create 5 WatchedProduct records with varying potential scores (92, 75, 88, 40, 60).
     * Assert products receive deterministic ranks 1 through 5 in descending order of score.
     */
    public function test_bulk_rank_service_assigns_deterministic_ranks_in_descending_order(): void
    {
        $p1 = WatchedProduct::factory()->create([
            'name' => 'Product 1 (Score 92)',
            'status' => WatchedProductStatus::Watch,
            'score' => 92,
            'rank' => null,
        ]);

        $p2 = WatchedProduct::factory()->create([
            'name' => 'Product 2 (Score 75)',
            'status' => WatchedProductStatus::Watch,
            'score' => 75,
            'rank' => null,
        ]);

        $p3 = WatchedProduct::factory()->create([
            'name' => 'Product 3 (Score 88)',
            'status' => WatchedProductStatus::Watch,
            'score' => 88,
            'rank' => null,
        ]);

        $p4 = WatchedProduct::factory()->create([
            'name' => 'Product 4 (Score 40)',
            'status' => WatchedProductStatus::Watch,
            'score' => 40,
            'rank' => null,
        ]);

        $p5 = WatchedProduct::factory()->create([
            'name' => 'Product 5 (Score 60)',
            'status' => WatchedProductStatus::Watch,
            'score' => 60,
            'rank' => null,
        ]);

        // Rejected product must have its rank cleared to null
        $pRejected = WatchedProduct::factory()->create([
            'name' => 'Product Rejected (Score 99)',
            'status' => WatchedProductStatus::Rejected,
            'score' => 99,
            'rank' => 1,
        ]);

        $rankService = app(RankService::class);
        $rankService->recalculateRanks();

        $this->assertSame(1, $p1->fresh()->rank, 'Score 92 must receive rank 1.');
        $this->assertSame(2, $p3->fresh()->rank, 'Score 88 must receive rank 2.');
        $this->assertSame(3, $p2->fresh()->rank, 'Score 75 must receive rank 3.');
        $this->assertSame(4, $p5->fresh()->rank, 'Score 60 must receive rank 4.');
        $this->assertSame(5, $p4->fresh()->rank, 'Score 40 must receive rank 5.');
        $this->assertNull($pRejected->fresh()->rank, 'Rejected product must receive rank null.');
    }

    /**
     * Scenario 4: Bulk RankService Query Efficiency.
     * Verify via DB::getQueryLog() that RankService performs the update as a batched CASE WHEN
     * statement without N+1 individual UPDATE queries per product.
     */
    public function test_bulk_rank_service_executes_single_batched_query_without_n_plus_one(): void
    {
        // Create 5 products with null ranks
        $products = [
            WatchedProduct::factory()->create(['status' => WatchedProductStatus::Watch, 'score' => 92, 'rank' => null]),
            WatchedProduct::factory()->create(['status' => WatchedProductStatus::Watch, 'score' => 75, 'rank' => null]),
            WatchedProduct::factory()->create(['status' => WatchedProductStatus::Watch, 'score' => 88, 'rank' => null]),
            WatchedProduct::factory()->create(['status' => WatchedProductStatus::Watch, 'score' => 40, 'rank' => null]),
            WatchedProduct::factory()->create(['status' => WatchedProductStatus::Watch, 'score' => 60, 'rank' => null]),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rankService = app(RankService::class);
        $rankService->recalculateRanks();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Find the batched CASE WHEN statement
        $batchedCaseUpdates = array_filter($queries, function (array $entry) {
            $sql = strtolower($entry['query']);

            return str_contains($sql, 'update')
                && str_contains($sql, 'case')
                && str_contains($sql, 'when id = ? then ?');
        });

        $this->assertCount(
            1,
            $batchedCaseUpdates,
            'RankService must execute exactly 1 batched CASE WHEN update query for all active products.'
        );

        // Assert 0 individual product update queries (confirming absence of N+1)
        $individualUpdates = array_filter($queries, function (array $entry) {
            $sql = strtolower($entry['query']);

            return preg_match('/update\s+[`"]?watched_products[`"]?\s+set\s+[`"]?rank[`"]?\s*=\s*\?\s*where\s+[`"]?id[`"]?\s*=/i', $sql);
        });

        $this->assertCount(
            0,
            $individualUpdates,
            'RankService must NOT execute individual N+1 UPDATE queries per product.'
        );

        // When ranks are already up to date, recalculateRanks should execute 0 update queries
        DB::flushQueryLog();
        DB::enableQueryLog();

        $rankService->recalculateRanks();

        $noOpQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $noOpCaseUpdates = array_filter($noOpQueries, function (array $entry) {
            $sql = strtolower($entry['query']);

            return str_contains($sql, 'update') && str_contains($sql, 'case');
        });

        $this->assertCount(
            0,
            $noOpCaseUpdates,
            'No CASE WHEN queries should execute when ranks are already synchronized.'
        );
    }
}
