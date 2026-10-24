<?php

namespace Tests\Feature;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\Track;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\Money;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerpApiQuotaAndRankRefactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement 1: Atomic Quota Enforcement in QuotaBudget.
     */
    public function test_quota_budget_atomic_spend_enforces_caps(): void
    {
        $budget = app(QuotaBudget::class);

        // Seed current period with 229 calls
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $budget->currentPeriod(),
            'calls' => 229,
        ]);

        // Weekly cap is 230: spending 1 succeeds
        $this->assertTrue($budget->atomicSpend(1, isManual: false));
        $this->assertEquals(230, $budget->callsUsedThisMonth());

        // Now at 230: another weekly spend fails atomically
        $this->assertFalse($budget->atomicSpend(1, isManual: false));
        $this->assertEquals(230, $budget->callsUsedThisMonth());

        // Manual cap is 250: manual spend succeeds
        $this->assertTrue($budget->atomicSpend(2, isManual: true));
        $this->assertEquals(232, $budget->callsUsedThisMonth());
    }

    /**
     * Requirement 1: Deterministic 12-hour cache layer in SerpApiClient.
     */
    public function test_serp_client_deterministic_cache_prevents_duplicate_burn(): void
    {
        config(['watching.serpapi_key' => 'test-api-key']);
        config(['watching.serpapi_cache_ttl_hours' => 12]);

        $client = app(SerpApiClient::class);
        $budget = app(QuotaBudget::class);

        // Verify deterministic cache key hashing
        $key1 = $client->buildCacheKey('search', 'Zenith Vitality', 'ca', 'en', 'desktop');
        $key2 = $client->buildCacheKey('search', '  zenith vitality  ', 'CA', 'EN', 'DESKTOP');
        $this->assertSame($key1, $key2);

        Http::fake([
            'https://serpapi.com/search.json*' => Http::response([
                'organic_results' => [
                    ['title' => 'Zenith Official', 'link' => 'https://zenith.example.com'],
                ],
                'ads' => [],
            ], 200),
        ]);

        $product1 = WatchedProduct::factory()->create(['name' => 'Zenith Vitality']);
        $product2 = WatchedProduct::factory()->create(['name' => 'zenith vitality']);

        // First product fetches via HTTP and consumes quota
        $client->fetchProductData($product1, forceFresh: false);
        $callsAfterFirst = $budget->callsUsedThisMonth();
        $this->assertGreaterThan(0, $callsAfterFirst);

        // Second product with identical query uses 12-hour deterministic cache: ZERO extra quota burned!
        $client->fetchProductData($product2, forceFresh: false);
        $this->assertEquals($callsAfterFirst, $budget->callsUsedThisMonth());
    }

    /**
     * Requirement 1: Quota >= 85% automatically restricts refreshes to high priority or active campaign products.
     */
    public function test_quota_high_restricts_refreshes_to_high_priority_and_active_campaigns(): void
    {
        $budget = app(QuotaBudget::class);

        // Cap is 230: 85% is ~195.5 calls. Seed with 200 calls (>= 85%).
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $budget->currentPeriod(),
            'calls' => 200,
        ]);

        $this->assertTrue($budget->isQuotaHigh());

        // Create an active campaign track
        Track::query()->create([
            'product_name' => 'Campaign Active Product',
            'campaign_ids' => ['campaign-12345'],
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);

        // Product A: Associated with active campaign track
        $productA = WatchedProduct::factory()->create([
            'name' => 'Campaign Active Product',
            'status' => WatchedProductStatus::Watch,
            'score' => 40,
        ]);

        // Product B: Test status (campaign preparation)
        $productB = WatchedProduct::factory()->create([
            'name' => 'Test Status Product',
            'status' => WatchedProductStatus::Test,
            'score' => 45,
        ]);

        // Product C: High potential score (score >= 60)
        $productC = WatchedProduct::factory()->create([
            'name' => 'High Potential Product',
            'status' => WatchedProductStatus::Watch,
            'score' => 85,
        ]);

        // Product D: Low score, watch status, no campaigns (regular candidate)
        $productD = WatchedProduct::factory()->create([
            'name' => 'Low Priority Unrelated Product',
            'status' => WatchedProductStatus::Watch,
            'score' => 25,
            'last_refreshed_at' => now()->subDays(30),
        ]);

        $selected = $budget->selectProductsForWeeklyRefresh();
        $selectedIds = $selected->pluck('id')->all();

        $this->assertContains($productA->id, $selectedIds);
        $this->assertContains($productB->id, $selectedIds);
        $this->assertContains($productC->id, $selectedIds);
        $this->assertNotContains($productD->id, $selectedIds);
    }

    /**
     * Requirement 2: RankService batched CASE WHEN SQL update.
     */
    public function test_rank_service_bulk_updates_ranks(): void
    {
        $p1 = WatchedProduct::factory()->create([
            'name' => 'Product Low Score',
            'status' => WatchedProductStatus::Watch,
            'score' => 30,
            'rank' => null,
        ]);

        $p2 = WatchedProduct::factory()->create([
            'name' => 'Product High Score',
            'status' => WatchedProductStatus::Watch,
            'score' => 90,
            'rank' => null,
        ]);

        $p3 = WatchedProduct::factory()->create([
            'name' => 'Product Rejected',
            'status' => WatchedProductStatus::Rejected,
            'score' => 99,
            'rank' => 1,
        ]);

        $rankService = app(RankService::class);
        $rankService->recalculateRanks();

        $this->assertEquals(1, $p2->fresh()->rank);
        $this->assertEquals(2, $p1->fresh()->rank);
        $this->assertNull($p3->fresh()->rank);
    }

    /**
     * Requirement 3: Defensive Scoring Fallbacks in PotentialScoreService.
     */
    public function test_defensive_scoring_fallbacks_for_missing_cpc(): void
    {
        $scoreService = app(PotentialScoreService::class);

        // Product with missing/zero CPC: should NOT artificially inflate margin headroom
        $product = WatchedProduct::factory()->create([
            'payout_amount' => 100.00,
            'payout_currency' => 'USD',
            'brand_cpc_amount' => 0.0,
            'kp_cpc_amount' => 0.0,
            'est_cvr' => 0.02,
        ]);

        $result = $scoreService->score($product);

        $this->assertArrayHasKey('margin_headroom', $result['breakdown']);
        $this->assertTrue($result['flags']['fallback_cpc_applied'] ?? false);
        $this->assertEquals('fallback', $result['flags']['cpc_confidence'] ?? null);
        $this->assertEquals(1.50, $result['flags']['fallback_cpc_cad'] ?? null);

        // Product with explicit auction CPC: marked with high confidence
        $productWithCpc = WatchedProduct::factory()->create([
            'payout_amount' => 100.00,
            'payout_currency' => 'USD',
            'brand_cpc_amount' => 1.25,
            'brand_cpc_currency' => 'USD',
            'est_cvr' => 0.02,
        ]);

        $resultWithCpc = $scoreService->score($productWithCpc);
        $this->assertEquals('high', $resultWithCpc['flags']['cpc_confidence'] ?? null);
        $this->assertFalse($resultWithCpc['flags']['fallback_cpc_applied'] ?? false);
    }

    /**
     * Requirement 3: Money model convertTo method uses FxRate consistently.
     */
    public function test_money_model_conversions(): void
    {
        $usdMoney = Money::make(100.00, 'USD');
        $cadAmount = $usdMoney->toCad();
        $this->assertGreaterThan(100.00, $cadAmount);

        $convertedBrl = $usdMoney->convertTo('BRL');
        $this->assertGreaterThan(0.0, $convertedBrl);
    }
}
