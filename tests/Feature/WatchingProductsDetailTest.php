<?php

namespace Tests\Feature;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\ProductNegativeKeyword;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\QuotaBudget;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchingProductsDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'owner',
        ]);

        $this->seed(NegativeKeywordSeedSeeder::class);
    }

    public function test_show_page_renders_with_inertia_and_props(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Test,
            'payout_amount' => 120.00,
            'payout_currency' => 'USD',
            'page_type' => 'TSL',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Show')
                ->has('product')
                ->where('product.name', 'Fimavyn Blood Sugar Formula')
                ->where('product.payout_amount', 120)
                ->has('score_history')
                ->has('negative_keywords')
                ->has('campaign_guidance')
                ->has('serpapi_usage')
                ->has('options')
            );
    }

    public function test_refresh_now_endpoint_executes_refresh_and_increments_serpapi_quota(): void
    {
        config(['watching.serpapi_key' => 'test_serp_key']);

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
                        ['values' => [['extracted_value' => 60]]],
                    ],
                ],
                'related_searches' => [
                    ['query' => 'fimavyn discount'],
                ],
            ], 200),
            '*' => Http::response('OK', 200),
        ]);

        $budget = app(QuotaBudget::class);
        $this->assertEquals(0, $budget->callsUsedThisMonth());

        $product = WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 120.00,
        ]);

        // First UI refresh: consumes 2 SerpApi calls (trends + google search)
        $response = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/refresh-now");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Product data refreshed via SerpApi. Total calls used this month: 2/250.');

        $product->refresh();
        $this->assertNotNull($product->last_refreshed_at);
        $this->assertEquals(2, $budget->callsUsedThisMonth());

        $apiUsage = ApiUsage::query()
            ->where('provider', 'serpapi')
            ->where('period', $budget->currentPeriod())
            ->first();
        $this->assertNotNull($apiUsage);
        $this->assertEquals(2, $apiUsage->calls);

        // Second UI refresh: must bypass cache (forceFresh = true) and consume another 2 calls
        $response2 = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/refresh-now");

        $response2->assertRedirect();
        $response2->assertSessionHas('success', 'Product data refreshed via SerpApi. Total calls used this month: 4/250.');
        $this->assertEquals(4, $budget->callsUsedThisMonth());

        // Artisan command refresh also increments by 2 calls on the same budget/usage table
        $this->artisan('watching:refresh --product='.$product->id)
            ->assertSuccessful();

        $this->assertEquals(6, $budget->callsUsedThisMonth());
    }

    public function test_update_strategy_endpoint_updates_strategy_and_syncs_rules(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Keto Trim Pro',
            'page_type' => 'TSL',
            'strategy' => Strategy::BofDirect,
            'strategy_source' => StrategySource::Manual,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/strategy", [
                'strategy' => Strategy::BofReview->value,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertEquals(Strategy::BofReview, $product->strategy);
        $this->assertEquals(StrategySource::Manual, $product->strategy_source);

        // Verify review seeds were disabled under bof_review
        $reviewKw = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', 'review')
            ->firstOrFail();

        $this->assertFalse((bool) $reviewKw->enabled);
    }

    public function test_negative_keyword_crud_endpoints(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Test Crud Product',
        ]);

        // 1. Store negative
        $storeResponse = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/negatives", [
                'term' => 'custom negative word',
                'match_type' => NegativeKeywordMatchType::Phrase->value,
            ]);

        $storeResponse->assertRedirect();
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'custom negative word',
            'match_type' => NegativeKeywordMatchType::Phrase->value,
            'source' => NegativeKeywordSource::Manual->value,
            'manually_overridden' => 1,
        ]);

        $kw = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', 'custom negative word')
            ->firstOrFail();

        // 2. Update negative (toggle enabled & match_type)
        $updateResponse = $this->actingAs($this->user)
            ->patch("/watching-products/{$product->id}/negatives/{$kw->id}", [
                'enabled' => false,
                'match_type' => NegativeKeywordMatchType::Exact->value,
            ]);

        $updateResponse->assertRedirect();
        $kw->refresh();
        $this->assertFalse((bool) $kw->enabled);
        $this->assertEquals(NegativeKeywordMatchType::Exact, $kw->match_type);
        $this->assertTrue((bool) $kw->manually_overridden);

        // 3. Delete negative
        $deleteResponse = $this->actingAs($this->user)
            ->delete("/watching-products/{$product->id}/negatives/{$kw->id}");

        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('product_negative_keywords', [
            'id' => $kw->id,
        ]);
    }

    public function test_export_negatives_csv_endpoint(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Alpha Gold',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}/negatives/export");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('negatives_alpha-gold_', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringContainsString('Keyword,"Match Type",Source,"Formatted Term"', $response->getContent() ?: '');
    }

    public function test_cron_run_route_security(): void
    {
        config(['app.cron_token' => 'hgytrsd543T4564FGHf7tgfs635GGf']);

        // Missing token -> 403
        $this->get('/cron/run')
            ->assertForbidden();

        // Wrong token -> 403
        $this->get('/cron/run?token=invalid_token')
            ->assertForbidden();

        // Correct token -> 200
        $this->get('/cron/run?token=hgytrsd543T4564FGHf7tgfs635GGf')
            ->assertOk()
            ->assertJson([
                'ok' => true,
            ]);
    }
}
