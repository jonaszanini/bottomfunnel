<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ComplianceRisk;
use App\Enums\Strategy;
use App\Enums\TrendsTrend;
use App\Enums\WatchedProductStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\PotentialScoreService;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductEnhancementsTest extends TestCase
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

    public function test_can_create_and_update_product_with_phase2_recheck_approval_and_multi_tier_payouts(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/watching-products', [
                'name' => 'Flow Neuro Plus',
                'status' => 'watch',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
                'strategy' => 'bof_direct',
                'strategy_phase2' => 'bof_review',
                'strategy_switch_trigger' => 'Switch to review after 50 sales or CPA > $45',
                'recheck_at' => now()->addDays(14)->toDateString(),
                'promotion_criteria' => 'Scale to test budget when 7-day ROAS >= 1.6 and EPC >= $2.00',
                'approval_status' => 'yes',
                'network_approval_status' => 'unknown',
                'compliance_risk' => 'not_assessed',
                'payout_amount' => 100.00,
                'payout_currency' => 'USD',
                'allowed_geos' => ['US', 'CA'],
                'payouts_by_platform' => [
                    [
                        'platform' => 'ClickBank',
                        'geo_tier' => 'US',
                        'amount' => 125.00,
                        'currency' => 'USD',
                        'model' => 'cpa_fixed',
                    ],
                    [
                        'platform' => 'ClickBank',
                        'geo_tier' => 'CA',
                        'amount' => 110.00,
                        'currency' => 'USD',
                        'model' => 'cpa_fixed',
                    ],
                ],
            ]);

        $response->assertRedirect('/watching-products');

        $this->assertDatabaseHas('watched_products', [
            'name' => 'Flow Neuro Plus',
            'strategy_phase2' => 'bof_review',
            'strategy_switch_trigger' => 'Switch to review after 50 sales or CPA > $45',
            'promotion_criteria' => 'Scale to test budget when 7-day ROAS >= 1.6 and EPC >= $2.00',
            'approval_status' => 'yes',
            'network_approval_status' => 'unknown',
            'compliance_risk' => 'not_assessed',
            'approval_required' => true,
        ]);

        $product = WatchedProduct::where('name', 'Flow Neuro Plus')->firstOrFail();
        $this->assertSame(Strategy::BofReview, $product->strategy_phase2);
        $this->assertSame(ApprovalStatus::Yes, $product->approval_status);
        $this->assertSame(ApprovalStatus::Unknown, $product->network_approval_status);
        $this->assertSame(ComplianceRisk::NotAssessed, $product->compliance_risk);
        $this->assertCount(2, $product->payouts_by_platform);
        $this->assertEquals('US', $product->payouts_by_platform[0]['geo_tier']);
        $this->assertEquals(125.00, $product->payouts_by_platform[0]['amount']);

        // Update product via controller
        $updateResponse = $this->actingAs($this->user)
            ->put("/watching-products/{$product->id}", [
                'name' => 'Flow Neuro Plus Ultra',
                'status' => 'test',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
                'strategy' => 'bof_review',
                'strategy_phase2' => null,
                'strategy_switch_trigger' => null,
                'recheck_at' => now()->addDays(30)->toDateString(),
                'promotion_criteria' => 'Graduated to test phase',
                'approval_status' => 'no',
                'network_approval_status' => 'no',
                'compliance_risk' => 'low',
                'payout_amount' => 130.00,
                'payout_currency' => 'USD',
            ]);

        $updateResponse->assertRedirect('/watching-products');

        $product->refresh();
        $this->assertEquals('Flow Neuro Plus Ultra', $product->name);
        $this->assertNull($product->strategy_phase2);
        $this->assertSame(ApprovalStatus::No, $product->approval_status);
        $this->assertSame(ApprovalStatus::No, $product->network_approval_status);
        $this->assertFalse($product->approval_required);
        $this->assertSame(ComplianceRisk::Low, $product->compliance_risk);
    }

    public function test_overdue_recheck_filter_and_metrics(): void
    {
        $overdueProduct = WatchedProduct::create([
            'name' => 'Overdue Product Alpha',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 50.00,
            'payout_currency' => 'USD',
            'recheck_at' => now()->subDays(2)->toDateString(),
        ]);

        $futureProduct = WatchedProduct::create([
            'name' => 'Future Product Beta',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 60.00,
            'payout_currency' => 'USD',
            'recheck_at' => now()->addDays(5)->toDateString(),
        ]);

        $nullRecheckProduct = WatchedProduct::create([
            'name' => 'Null Recheck Gamma',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 70.00,
            'payout_currency' => 'USD',
            'recheck_at' => null,
        ]);

        $this->assertTrue($overdueProduct->recheck_at->lte(today()));
        $this->assertFalse($futureProduct->recheck_at->lte(today()));

        $response = $this->actingAs($this->user)
            ->get('/watching-products?recheck_overdue=true');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('WatchingProducts/Index')
            ->has('products', 1)
            ->where('products.0.name', 'Overdue Product Alpha')
            ->where('products.0.is_recheck_overdue', true)
            ->where('metrics.overdue_recheck_count', 1)
        );
    }

    public function test_compliance_risk_backfill_command(): void
    {
        // 1. Low + empty notes -> becomes not_assessed
        $eligible = WatchedProduct::create([
            'name' => 'Generic Blood Sugar',
            'status' => WatchedProductStatus::Watch,
            'compliance_risk' => ComplianceRisk::Low,
            'compliance_notes' => null,
        ]);

        // 2. Straivo -> must stay low
        $straivo = WatchedProduct::create([
            'name' => 'Straivo Hair Restorer',
            'status' => WatchedProductStatus::Watch,
            'compliance_risk' => ComplianceRisk::Low,
            'compliance_notes' => null,
        ]);

        // 3. Low + with notes -> must stay low
        $withNotes = WatchedProduct::create([
            'name' => 'Prostate Shield',
            'status' => WatchedProductStatus::Watch,
            'compliance_risk' => ComplianceRisk::Low,
            'compliance_notes' => 'Verified low risk compliant terms with affiliate manager.',
        ]);

        // 4. Medium -> stays medium
        $medium = WatchedProduct::create([
            'name' => 'Weight Shred 360',
            'status' => WatchedProductStatus::Watch,
            'compliance_risk' => ComplianceRisk::Medium,
            'compliance_notes' => null,
        ]);

        // Dry-run should write nothing
        $this->artisan('watching:backfill-compliance --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $eligible->refresh();
        $this->assertSame(ComplianceRisk::Low, $eligible->compliance_risk);

        // Real run
        $this->artisan('watching:backfill-compliance')
            ->assertSuccessful();

        $eligible->refresh();
        $straivo->refresh();
        $withNotes->refresh();
        $medium->refresh();

        $this->assertSame(ComplianceRisk::NotAssessed, $eligible->compliance_risk);
        $this->assertSame(ComplianceRisk::Low, $straivo->compliance_risk);
        $this->assertSame(ComplianceRisk::Low, $withNotes->compliance_risk);
        $this->assertSame(ComplianceRisk::Medium, $medium->compliance_risk);

        // Idempotent: running again updates 0
        $this->artisan('watching:backfill-compliance')
            ->expectsOutputToContain('No products require compliance risk backfill.')
            ->assertSuccessful();
    }

    public function test_approval_status_tri_state_mapping_and_scoring_neutrality(): void
    {
        $this->assertTrue(ApprovalStatus::Yes->isRequired());
        $this->assertFalse(ApprovalStatus::No->isRequired());
        $this->assertFalse(ApprovalStatus::Unknown->isRequired());

        // Test scoring neutrality:
        // Freshness score: base 5 pts. Deducts 2 pts ONLY when approval_status is Yes.
        // Unknown and No do NOT deduct.
        $scoreService = app(PotentialScoreService::class);

        $productUnknown = new WatchedProduct([
            'name' => 'Product Unknown',
            'status' => WatchedProductStatus::Watch,
            'approval_status' => ApprovalStatus::Unknown,
            'last_reviewed_at' => now(),
        ]);

        $productNo = new WatchedProduct([
            'name' => 'Product No',
            'status' => WatchedProductStatus::Watch,
            'approval_status' => ApprovalStatus::No,
            'last_reviewed_at' => now(),
        ]);

        $productYes = new WatchedProduct([
            'name' => 'Product Yes',
            'status' => WatchedProductStatus::Watch,
            'approval_status' => ApprovalStatus::Yes,
            'last_reviewed_at' => now(),
        ]);

        $scoreUnknown = $scoreService->score($productUnknown)['breakdown']['freshness'];
        $scoreNo = $scoreService->score($productNo)['breakdown']['freshness'];
        $scoreYes = $scoreService->score($productYes)['breakdown']['freshness'];

        $this->assertEquals(5, $scoreUnknown);
        $this->assertEquals(5, $scoreNo);
        $this->assertEquals(3, $scoreYes); // 5 - 2 = 3
    }

    public function test_geo_matched_payout_scoring_prioritizes_allowed_geos(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Keto Multi Tier',
            'status' => WatchedProductStatus::Watch,
            'allowed_geos' => ['CA'],
            'payout_amount' => 100.00,
            'payout_currency' => 'USD',
            'payouts_by_platform' => [
                [
                    'platform' => 'ClickBank',
                    'geo_tier' => 'US',
                    'amount' => 160.00,
                    'currency' => 'USD',
                ],
                [
                    'platform' => 'ClickBank',
                    'geo_tier' => 'CA',
                    'amount' => 140.00,
                    'currency' => 'USD',
                ],
            ],
        ]);

        // When allowed_geos has CA, scoring payout matches CA tier (140.00)
        $scoringPayout = $product->scoringPayout();
        $this->assertEquals(140.00, $scoringPayout['amount']);
        $this->assertEquals('CA', $scoringPayout['geo_tier']);

        // When allowed_geos is empty, scoring payout returns the highest payout tier (160.00)
        $product->allowed_geos = [];
        $product->save();

        $scoringHighest = $product->scoringPayout();
        $this->assertEquals(160.00, $scoringHighest['amount']);
        $this->assertEquals('US', $scoringHighest['geo_tier']);
    }

    public function test_create_campaign_prefill_and_track_form_props(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Audien Hearing Aid',
            'status' => WatchedProductStatus::Watch,
            'strategy' => Strategy::BofDirect,
            'allowed_geos' => ['US', 'CA'],
            'negatives' => "free\ncoupon\nlogin",
        ]);

        $response = $this->actingAs($this->user)
            ->get('/tracks/create?initial_name='.urlencode('Audien Hearing Aid - US - bof_direct - 2026-10-25').
                '&initial_strategy=bof_direct&initial_geos=US%2CCA&initial_negatives='.urlencode("free\ncoupon\nlogin").
                "&watched_product_id={$product->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Tracks/Form')
            ->where('initial_name', 'Audien Hearing Aid - US - bof_direct - 2026-10-25')
            ->where('initial_strategy', 'bof_direct')
            ->where('initial_geos', 'US,CA')
            ->where('watched_product_id', (string) $product->id)
            ->has('initial_negatives')
        );
    }

    public function test_demand_trend_evaluated_as_unknown_when_metrics_are_zero_or_null(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Zero Demand Product',
            'status' => WatchedProductStatus::Watch,
            'trends_7d' => 0,
            'trends_30d' => 0,
            'trends_90d' => 0,
            'trends_trend' => TrendsTrend::Stable, // Stale column value
        ]);

        // When all 3 metrics are zero or null, effectiveTrendsTrend() evaluates to Unknown
        $this->assertSame(TrendsTrend::Unknown, $product->effectiveTrendsTrend());

        // And demand score evaluates as neutral base 8 pts instead of stable (16 pts)
        $scoreService = app(PotentialScoreService::class);
        $demandScore = $scoreService->score($product)['breakdown']['demand'];

        $this->assertEquals(8, $demandScore);
    }

    public function test_show_page_props_contain_all_tabs_data(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Comprehensive Watch Item',
            'status' => WatchedProductStatus::Watch,
            'strategy' => Strategy::BofDirect,
            'strategy_phase2' => Strategy::BofReview,
            'strategy_switch_trigger' => 'Switch after 100 conversions',
            'recheck_at' => now()->addDays(7)->toDateString(),
            'promotion_criteria' => 'Scale to $200/day after 3 days ROAS > 1.8',
            'approval_status' => ApprovalStatus::Yes,
            'network_approval_status' => ApprovalStatus::Unknown,
            'compliance_risk' => ComplianceRisk::NotAssessed,
            'payout_amount' => 75.00,
            'payout_currency' => 'USD',
            'payouts_by_platform' => [
                ['platform' => 'ClickBank', 'geo_tier' => 'US', 'amount' => 75.00, 'currency' => 'USD', 'model' => 'cpa_fixed'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('WatchingProducts/Show')
            ->where('product.name', 'Comprehensive Watch Item')
            ->where('product.strategy_phase2', 'bof_review')
            ->where('product.strategy_switch_trigger', 'Switch after 100 conversions')
            ->where('product.promotion_criteria', 'Scale to $200/day after 3 days ROAS > 1.8')
            ->where('product.approval_status', 'yes')
            ->where('product.network_approval_status', 'unknown')
            ->where('product.compliance_risk', 'not_assessed')
            ->has('options.approval_statuses')
            ->has('options.compliance_risks')
            ->has('options.strategies')
        );
    }
}
