<?php

namespace Tests\Feature;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuotaBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_never_exceeds_monthly_cap(): void
    {
        $budget = app(QuotaBudget::class);

        // Record 229 calls
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $budget->currentPeriod(),
            'calls' => 229,
        ]);

        $this->assertTrue($budget->canSpendWeekly(1)); // 230 reaches cap
        $this->assertFalse($budget->canSpendWeekly(2)); // 231 exceeds 230 cap
        $this->assertTrue($budget->canSpendManual(2)); // 231 is under 250 reserve limit
    }

    public function test_priority_order_for_weekly_refresh(): void
    {
        $budget = app(QuotaBudget::class);

        // Product 1: Never refreshed, created 2 days ago
        $p1 = WatchedProduct::factory()->create([
            'name' => 'Product 1 (Never refreshed older)',
            'status' => WatchedProductStatus::Watch,
            'last_refreshed_at' => null,
            'score' => 40,
            'created_at' => now()->subDays(2),
        ]);

        // Product 2: Never refreshed, created 1 hour ago (newest)
        $p2 = WatchedProduct::factory()->create([
            'name' => 'Product 2 (Never refreshed newest)',
            'status' => WatchedProductStatus::Watch,
            'last_refreshed_at' => null,
            'score' => 30,
            'created_at' => now()->subHour(),
        ]);

        // Product 3: Refreshed, high score
        $p3 = WatchedProduct::factory()->create([
            'name' => 'Product 3 (High score refreshed)',
            'status' => WatchedProductStatus::Watch,
            'last_refreshed_at' => now()->subDays(5),
            'score' => 90,
            'created_at' => now()->subDays(10),
        ]);

        // Product 4: Refreshed, low score, older refresh date
        $p4 = WatchedProduct::factory()->create([
            'name' => 'Product 4 (Oldest refresh)',
            'status' => WatchedProductStatus::Watch,
            'last_refreshed_at' => now()->subDays(14),
            'score' => 50,
            'created_at' => now()->subDays(20),
        ]);

        // Product 5: Rejected product (should be skipped)
        $p5 = WatchedProduct::factory()->create([
            'name' => 'Product 5 (Rejected)',
            'status' => WatchedProductStatus::Rejected,
            'last_refreshed_at' => null,
        ]);

        $selected = $budget->selectProductsForWeeklyRefresh();

        // Product 2 (never refreshed newest) should precede Product 1
        $ids = $selected->pluck('id')->all();
        $this->assertContains($p2->id, $ids);
        $this->assertNotContains($p5->id, $ids);

        $posP2 = array_search($p2->id, $ids, true);
        $posP1 = array_search($p1->id, $ids, true);
        $this->assertLessThan($posP1, $posP2);
    }

    public function test_serp_client_keeps_old_data_on_429(): void
    {
        config(['watching.serpapi_key' => 'test_key']);

        Http::fake([
            'https://serpapi.com/search.json*' => Http::response('Rate limit reached', 429),
        ]);

        $product = WatchedProduct::factory()->create([
            'trends_30d' => 45.0,
            'advertiser_count' => 3,
        ]);

        $client = app(SerpApiClient::class);
        $result = $client->fetchProductData($product);

        $this->assertFalse($result['success']);
        // Assert old values are preserved
        $product->refresh();
        $this->assertEquals(45.0, (float) $product->trends_30d);
        $this->assertEquals(3, $product->advertiser_count);
    }

    public function test_command_dry_run_makes_no_http_calls(): void
    {
        Http::fake();

        $this->artisan('watching:refresh --dry-run')
            ->expectsOutputToContain('Dry Run')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_real_refresh_run_writes_history_and_recalculates_ranks(): void
    {
        config(['watching.serpapi_key' => 'test_key']);

        Http::fake([
            'https://api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-10-21',
                'rates' => ['CAD' => 1.38],
            ], 200),
            'https://serpapi.com/search.json*' => Http::response([
                'interest_over_time' => [
                    'timeline_data' => [
                        ['values' => [['extracted_value' => 30]]],
                        ['values' => [['extracted_value' => 50]]],
                    ],
                ],
                'related_searches' => [
                    ['query' => 'product scam'],
                ],
                'ads' => [
                    ['title' => 'Ad 1'],
                ],
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $product = WatchedProduct::factory()->create([
            'name' => 'Alpha Steel Vitality',
            'status' => WatchedProductStatus::Test,
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'payout_amount' => 100.00,
        ]);

        $initialHistoryCount = ProductScoreHistory::query()->count();

        $this->artisan('watching:refresh --product='.$product->id)
            ->assertSuccessful();

        // Verify history row created
        $this->assertGreaterThan($initialHistoryCount, ProductScoreHistory::query()->count());

        // Verify product rank is set
        $product->refresh();
        $this->assertNotNull($product->rank);
        $this->assertNotNull($product->score);
    }
}
