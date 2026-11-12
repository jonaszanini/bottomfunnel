<?php

namespace Tests\Feature;

use App\Enums\AffiliateApplicationStatus;
use App\Enums\BrandBiddingStatus;
use App\Enums\FlowspyTrafficTrend;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\TrendsTrend;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\ProductScoreHistory;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\RankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PotentialScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private PotentialScoreService $scoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoreService = app(PotentialScoreService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeQualityProduct(array $overrides = []): WatchedProduct
    {
        return WatchedProduct::factory()->create(array_merge([
            'status' => WatchedProductStatus::Watch,
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'bof_homonym' => false,
            'true_bof' => TrueBofStatus::True,
            'trends_7d' => 45.0,
            'trends_30d' => 50.0,
            'trends_90d' => 48.0,
            'trends_trend' => TrendsTrend::Rising,
            'payout_amount' => 120.00,
            'payout_currency' => 'USD',
            'payout_model' => PayoutModel::CpaFixed,
            'page_type' => 'TSL',
            'funnel_flags' => [],
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'geos' => ['US', 'CA', 'AU'],
            'advertiser_count' => 1,
            'vendor_bids_brand' => false,
            'approval_required' => false,
            'cancel_rate' => 5.0,
            'listing_status' => ListingStatus::Active,
            'restricted_category' => false,
            'est_cvr' => 0.02,
            'brand_cpc_amount' => 0.50,
            'brand_cpc_currency' => 'USD',
            'last_refreshed_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedVolume(int $total): array
    {
        return [
            'search_volume_total' => $total,
            'search_volume_checked_at' => now(),
        ];
    }

    public function test_perfect_product_scores_high(): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume(5000));

        $result = $this->scoreService->score($product);

        $this->assertGreaterThanOrEqual(85, $result['score']);
        $this->assertEquals(20, $result['breakdown']['bof_name']);
        $this->assertEquals(20, $result['breakdown']['demand']);
        $this->assertEquals(15, $result['breakdown']['payout']);
        $this->assertEquals(10, $result['breakdown']['page_type']);
        $this->assertEquals(10, $result['breakdown']['brand_bidding']);
        $this->assertEquals(5, $result['breakdown']['geo']);
        $this->assertEquals(5, $result['breakdown']['competition']);
        $this->assertEquals(5, $result['breakdown']['freshness']);
    }

    public function test_same_niche_homonym_hard_discards_to_zero(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 100,
            'bof_homonym' => true, // Homonym
            'trends_30d' => 80.0,
            'payout_amount' => 150.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(0, $result['score']);
        $this->assertTrue($result['flags']['false_bof'] ?? false);
        $this->assertEquals(0, $result['breakdown']['bof_name']);
    }

    public function test_compound_qualifier_gets_partial_credit(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => false,
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 60,
            'bof_qualifier' => 'Drops',
            'bof_homonym' => false,
            'trends_30d' => 30.0,
            'payout_amount' => 80.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(12, $result['breakdown']['bof_name']);
        $this->assertTrue($result['flags']['compound_keyword_needed'] ?? false);
    }

    public function test_payout_below_floor_caps_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'trends_30d' => 50.0,
            'payout_amount' => 12.00, // Below $20 USD floor
            'payout_currency' => 'USD',
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertTrue($result['flags']['payout_below_floor'] ?? false);
    }

    public function test_dead_post_peak_caps_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'trends_trend' => TrendsTrend::DeadPostPeak,
            'trends_7d' => 4.5,
            'trends_30d' => 8.2,
            'trends_90d' => 52.0,
            'payout_amount' => 100.00,
            ...$this->confirmedVolume(5000),
        ]);

        $result = $this->scoreService->score($product);

        // BoF Triad component is 20/20 intact
        $this->assertEquals(20, $result['breakdown']['bof_name']);
        // Demand component drops to 0
        $this->assertEquals(0, $result['breakdown']['demand']);
        // Hard gate caps overall score at exactly 20
        $this->assertEquals(20, $result['score']);
        // Flags and audit data are populated
        $this->assertTrue($result['flags']['dead_post_peak'] ?? false);
        $this->assertArrayHasKey('dead_post_peak_audit', $result['flags']);
        $this->assertEquals(4.5, $result['flags']['dead_post_peak_audit']['trends_7d']);
        $this->assertEquals(8.2, $result['flags']['dead_post_peak_audit']['trends_30d']);
        $this->assertEquals(52.0, $result['flags']['dead_post_peak_audit']['trends_90d']);
        $this->assertStringContainsString('Demand collapsed post-peak', $result['flags']['dead_post_peak_audit']['reason']);
        $this->assertContains('dead_post_peak', $result['flags']['hard_gates_hit']);
    }

    public function test_pure_vsl_caps_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'page_type' => 'VSL',
            'funnel_flags' => ['vsl_only' => true],
            'payout_amount' => 100.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertEquals(0, $result['breakdown']['page_type']);
        $this->assertTrue($result['flags']['pure_vsl_or_iframe'] ?? false);
    }

    public function test_high_cancel_rate_caps_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'cancel_rate' => 28.5, // >= 25%
            'payout_amount' => 100.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertTrue($result['flags']['high_cancel_rate'] ?? false);
    }

    public function test_expired_listing_caps_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'listing_status' => ListingStatus::Expired,
            'payout_amount' => 100.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertTrue($result['flags']['expired_listing'] ?? false);
    }

    public function test_restricted_category_caps_score_at_50(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'trends_30d' => 80.0,
            'payout_amount' => 150.00,
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'restricted_category' => true, // Caps at 50
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(50, $result['score']);
        $this->assertTrue($result['flags']['restricted_category'] ?? false);
    }

    public function test_low_kp_cpc_triggers_audit_flag(): void
    {
        $product = WatchedProduct::factory()->create([
            'kp_cpc_amount' => 0.40, // ~R$2.28 (below R$4.00)
            'kp_cpc_currency' => 'USD',
        ]);

        $result = $this->scoreService->score($product);

        $this->assertTrue($result['flags']['audit_false_bof'] ?? false);
    }

    public function test_gradient_band_boundaries(): void
    {
        $getBand = function (int $score): string {
            if ($score > 75) {
                return 'green';
            }
            if ($score > 50) {
                return 'yellow-green';
            }
            if ($score > 20) {
                return 'amber';
            }

            return 'red';
        };

        // Red band 0-20
        $this->assertEquals('red', $getBand(0));
        $this->assertEquals('red', $getBand(20));

        // Amber band 21-50
        $this->assertEquals('amber', $getBand(21));
        $this->assertEquals('amber', $getBand(50));

        // Yellow-green band 51-75
        $this->assertEquals('yellow-green', $getBand(51));
        $this->assertEquals('yellow-green', $getBand(75));

        // Green band 76-100
        $this->assertEquals('green', $getBand(76));
        $this->assertEquals('green', $getBand(100));
    }

    public function test_product_with_all_three_triad_checks_passing_receives_full_20_points_on_bof_component(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 90, // Minimum for full credit is 90
            'bof_homonym' => false,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(20, $result['breakdown']['bof_name']);
        $this->assertArrayNotHasKey('false_bof', $result['flags']);
    }

    public function test_product_with_image_match_below_90_does_not_receive_full_20_points(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 89, // Below 90 threshold
            'bof_homonym' => false,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThan(20, $result['breakdown']['bof_name']);
        $this->assertEquals(14, $result['breakdown']['bof_name']); // round(0.89 * 10) + 3 + 2 = 9 + 5 = 14
    }

    public function test_product_with_missing_vpn_serp_does_not_receive_full_20_points(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 95,
            'bof_homonym' => false,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThan(20, $result['breakdown']['bof_name']);
        $this->assertEquals(13, $result['breakdown']['bof_name']); // round(0.95 * 10) + 3 = 10 + 3 = 13
    }

    public function test_demand_component_scores_rising_trend_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Rising,
            'trends_7d' => 45.0,
            'trends_30d' => 35.0,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(20, $result['breakdown']['demand']);
    }

    public function test_demand_component_scores_stable_trend_at_16(): void
    {
        $product = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Stable,
            'trends_7d' => 30.0,
            'trends_30d' => 30.0,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(16, $result['breakdown']['demand']);
    }

    public function test_demand_component_scores_falling_trend_at_10(): void
    {
        $product = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Falling,
            'trends_7d' => 15.0,
            'trends_30d' => 35.0,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(10, $result['breakdown']['demand']);
    }

    public function test_demand_component_scores_dead_post_peak_at_zero_and_caps_total_score_at_20(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'trends_trend' => TrendsTrend::DeadPostPeak,
            'trends_7d' => 5.0,
            'trends_30d' => 10.0,
            'trends_90d' => 80.0,
            'payout_amount' => 150.00,
            ...$this->confirmedVolume(5000),
        ]);

        $result = $this->scoreService->score($product);

        $this->assertEquals(0, $result['breakdown']['demand']);
        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertTrue($result['flags']['dead_post_peak'] ?? false);
        $this->assertContains('dead_post_peak', $result['flags']['hard_gates_hit']);
    }

    public function test_demand_component_scores_null_or_unknown_trend_at_8(): void
    {
        $productNull = WatchedProduct::factory()->create([
            'trends_trend' => null,
            'kp_volume' => null,
            'flowspy_traffic_trend' => null,
        ]);

        $resultNull = $this->scoreService->score($productNull);
        $this->assertEquals(8, $resultNull['breakdown']['demand']);

        $productRaw = new WatchedProduct;
        $productRaw->setRawAttributes([
            'trends_trend' => 'unrecognized_trend',
            'kp_volume' => null,
            'flowspy_traffic_trend' => null,
        ]);

        $resultRaw = $this->scoreService->score($productRaw);
        $this->assertEquals(8, $resultRaw['breakdown']['demand']);
    }

    public function test_flowspy_rising_yields_high_demand_score(): void
    {
        // Falling trend (normally 10) boosted to 18 by FlowSpy rising
        $productFalling = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Falling,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::Rising,
        ]);

        $resultFalling = $this->scoreService->score($productFalling);
        $this->assertEquals(18, $resultFalling['breakdown']['demand']);

        // Null trend (normally 8) boosted to 18 by FlowSpy rising
        $productNull = WatchedProduct::factory()->create([
            'trends_trend' => null,
            'kp_volume' => null,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::Rising,
        ]);

        $resultNull = $this->scoreService->score($productNull);
        $this->assertEquals(18, $resultNull['breakdown']['demand']);

        // Dead post peak remains hard gate at 0 demand even if FlowSpy is rising
        $productDead = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::DeadPostPeak,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::Rising,
        ]);

        $resultDead = $this->scoreService->score($productDead);
        $this->assertEquals(0, $resultDead['breakdown']['demand']);
    }

    public function test_demand_point_values_are_configurable(): void
    {
        config([
            'watching.demand_points.falling' => 12,
            'watching.demand_points.stable' => 15,
            'watching.demand_points.rising' => 19,
            'watching.demand_points.unknown' => 7,
            'watching.demand_points.flowspy_rising_min' => 20,
        ]);

        $fallingProduct = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Falling,
            'flowspy_traffic_trend' => null,
        ]);
        $this->assertEquals(12, $this->scoreService->score($fallingProduct)['breakdown']['demand']);

        $stableProduct = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Stable,
            'flowspy_traffic_trend' => null,
        ]);
        $this->assertEquals(15, $this->scoreService->score($stableProduct)['breakdown']['demand']);

        $risingProduct = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Rising,
            'flowspy_traffic_trend' => null,
        ]);
        $this->assertEquals(19, $this->scoreService->score($risingProduct)['breakdown']['demand']);

        $unknownProduct = WatchedProduct::factory()->create([
            'trends_trend' => null,
            'kp_volume' => null,
            'flowspy_traffic_trend' => null,
        ]);
        $this->assertEquals(7, $this->scoreService->score($unknownProduct)['breakdown']['demand']);

        $boostedProduct = WatchedProduct::factory()->create([
            'trends_trend' => TrendsTrend::Falling,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::Rising,
        ]);
        $this->assertEquals(20, $this->scoreService->score($boostedProduct)['breakdown']['demand']);
    }

    /**
     * @return array<string, array{0: int, 1: float}>
     */
    public static function volumeAnchorCases(): array
    {
        return [
            'below_floor' => [9, 0.0],
            'anchor_10' => [10, 0.0],
            'anchor_150' => [150, 2.5],
            'anchor_1000' => [1000, 8.0],
            'anchor_2000' => [2000, 9.0],
            'anchor_5000' => [5000, 10.0],
            'above_ceiling' => [8000, 10.0],
        ];
    }

    #[DataProvider('volumeAnchorCases')]
    public function test_volume_subscore_matches_configured_anchors(int $volume, float $expectedV): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume($volume));

        $result = $this->scoreService->score($product);

        $this->assertSame($expectedV, $result['breakdown']['v']);
        $this->assertArrayNotHasKey('volume_no_data', $result['flags']);
    }

    public function test_volume_interpolation_midpoint_between_10_and_150(): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume(39));

        $result = $this->scoreService->score($product);

        $this->assertEqualsWithDelta(1.26, $result['breakdown']['v'], 0.01);
    }

    public function test_missing_volume_data_flags_and_caps_score_at_69(): void
    {
        $product = $this->makeQualityProduct([
            'search_volume_total' => null,
            'search_volume_checked_at' => null,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertSame(0.0, $result['breakdown']['v']);
        $this->assertTrue($result['flags']['volume_no_data'] ?? false);
        $this->assertSame(100, $result['breakdown']['q']);
        $this->assertSame(69, $result['score']);
        $this->assertLessThanOrEqual(69, $result['score']);
    }

    public function test_confirmed_zero_volume_is_real_data_without_no_data_flag(): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume(0));

        $result = $this->scoreService->score($product);

        $this->assertSame(0.0, $result['breakdown']['v']);
        $this->assertArrayNotHasKey('volume_no_data', $result['flags']);
        $this->assertSame(100, $result['breakdown']['q']);
        $this->assertSame(85, $result['score']);
    }

    public function test_volume_below_eight_caps_score_at_89(): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume(708));

        $result = $this->scoreService->score($product);

        $this->assertLessThan(8.0, $result['breakdown']['v']);
        $this->assertSame(100, $result['breakdown']['q']);
        $this->assertSame(89, $result['score']);
    }

    public function test_hard_gates_still_bind_after_volume_blend(): void
    {
        $falseBof = $this->makeQualityProduct(array_merge($this->confirmedVolume(5000), [
            'bof_homonym' => true,
        ]));
        $falseBofResult = $this->scoreService->score($falseBof);
        $this->assertSame(0, $falseBofResult['score']);
        $this->assertTrue($falseBofResult['flags']['false_bof'] ?? false);

        $hardGate = $this->makeQualityProduct(array_merge($this->confirmedVolume(5000), [
            'cancel_rate' => 40.0,
        ]));
        $hardGateResult = $this->scoreService->score($hardGate);
        $this->assertSame(20, $hardGateResult['score']);
        $this->assertTrue($hardGateResult['flags']['high_cancel_rate'] ?? false);

        $restricted = $this->makeQualityProduct(array_merge($this->confirmedVolume(5000), [
            'restricted_category' => true,
        ]));
        $restrictedResult = $this->scoreService->score($restricted);
        $this->assertSame(50, $restrictedResult['score']);
        $this->assertTrue($restrictedResult['flags']['restricted_category'] ?? false);
    }

    public function test_dentavive_like_fixture_with_q_100_and_volume_150_scores_83(): void
    {
        $product = $this->makeQualityProduct($this->confirmedVolume(150));

        $result = $this->scoreService->score($product);

        $this->assertSame(100, $result['breakdown']['q']);
        $this->assertSame(2.5, $result['breakdown']['v']);
        $this->assertSame(83, $result['score']);
        $this->assertLessThanOrEqual(89, $result['score']);
    }

    public function test_vivalis_like_fixture_with_q_near_94_and_volume_1000_scores_above_90(): void
    {
        $product = $this->makeQualityProduct(array_merge($this->confirmedVolume(1000), [
            'trends_trend' => TrendsTrend::Stable,
            'approval_required' => true,
        ]));

        $result = $this->scoreService->score($product);

        $this->assertSame(94, $result['breakdown']['q']);
        $this->assertSame(8.0, $result['breakdown']['v']);
        $this->assertGreaterThan(90, $result['score']);
    }

    public function test_herpafend_like_fixture_with_q_87_and_volume_4540_scores_above_90(): void
    {
        $product = $this->makeQualityProduct(array_merge($this->confirmedVolume(4540), [
            'trends_trend' => TrendsTrend::Falling,
            'geos' => ['US'],
        ]));

        $result = $this->scoreService->score($product);

        $this->assertSame(87, $result['breakdown']['q']);
        $this->assertGreaterThan(90, $result['score']);
    }

    public function test_search_volume_is_not_fed_into_demand_component(): void
    {
        $product = WatchedProduct::factory()->create(array_merge($this->confirmedVolume(5000), [
            'trends_trend' => null,
            'kp_volume' => null,
            'flowspy_traffic_trend' => null,
        ]));

        $result = $this->scoreService->score($product);

        $this->assertSame(8, $result['breakdown']['demand']);
        $this->assertSame(10.0, $result['breakdown']['v']);
    }

    public function test_score_input_fields_constant_lists_every_scoring_input(): void
    {
        $fields = PotentialScoreService::SCORE_INPUT_FIELDS;

        foreach ([
            'search_volume_total',
            'search_volume_checked_at',
            'est_cvr',
            'kp_yoy',
            'kp_3m',
            'kp_cpc_amount',
            'kp_cpc_currency',
            'brand_cpc_amount',
            'brand_cpc_currency',
            'funnel_flags',
            'geos',
            'payout_cad',
            'true_bof',
        ] as $field) {
            $this->assertContains($field, $fields);
        }

        $this->assertNotContains('notes', $fields);
        $this->assertNotContains('affiliate_application_status', $fields);
        $this->assertNotContains('status', $fields);
    }

    public function test_updating_est_cvr_rescores_without_changing_status(): void
    {
        $user = User::factory()->create();
        $product = $this->makeQualityProduct($this->confirmedVolume(5000));
        $initialScore = $product->fresh()->score;
        $status = $product->status;
        $historyBefore = ProductScoreHistory::query()->where('product_id', $product->id)->count();

        $response = $this->actingAs($user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'est_cvr' => 0.001,
        ]);

        $response->assertSessionHasNoErrors();
        $fresh = $product->fresh();

        $this->assertNotSame($initialScore, $fresh->score);
        $this->assertSame($status, $fresh->status);
        $this->assertEqualsWithDelta(0.001, (float) $fresh->est_cvr, 0.0001);
        $this->assertSame(
            $historyBefore + 1,
            ProductScoreHistory::query()->where('product_id', $product->id)->count(),
            'Updating a scoring field should write exactly one new score history row',
        );
    }

    public function test_updating_notes_or_affiliate_application_status_does_not_rescore(): void
    {
        $user = User::factory()->create();
        $product = $this->makeQualityProduct($this->confirmedVolume(150));
        $initialScore = $product->fresh()->score;
        $initialScoredAt = $product->fresh()->scored_at?->toIso8601String();
        $status = $product->status;

        $this->actingAs($user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'notes' => 'Operator notes only',
        ])->assertSessionHasNoErrors();

        $afterNotes = $product->fresh();
        $this->assertSame($initialScore, $afterNotes->score);
        $this->assertSame($initialScoredAt, $afterNotes->scored_at?->toIso8601String());
        $this->assertSame($status, $afterNotes->status);
        $this->assertSame('Operator notes only', $afterNotes->notes);

        $this->actingAs($user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => AffiliateApplicationStatus::Pending->value,
        ])->assertSessionHasNoErrors();

        $afterApp = $product->fresh();
        $this->assertSame($initialScore, $afterApp->score);
        $this->assertSame($status, $afterApp->status);
        $this->assertSame(AffiliateApplicationStatus::Pending, $afterApp->affiliate_application_status);
    }

    public function test_rescore_does_not_change_product_status(): void
    {
        $product = $this->makeQualityProduct(array_merge($this->confirmedVolume(150), [
            'status' => WatchedProductStatus::Watch,
        ]));
        $originalStatus = $product->status;

        $this->scoreService->persistScore($product);

        $fresh = $product->fresh();
        $this->assertSame($originalStatus, $fresh->status);
        $this->assertSame(WatchedProductStatus::Watch, $fresh->status);
        $this->assertSame(83, $fresh->score);
    }

    public function test_rank_tie_break_uses_search_volume_total(): void
    {
        $lowVolume = $this->makeQualityProduct(array_merge($this->confirmedVolume(150), [
            'name' => 'Low Volume Tie',
            'score' => 83,
            'trends_30d' => null,
        ]));
        $highVolume = $this->makeQualityProduct(array_merge($this->confirmedVolume(5000), [
            'name' => 'High Volume Tie',
            'score' => 83,
            'trends_30d' => null,
        ]));

        WatchedProduct::query()->whereKey([$lowVolume->id, $highVolume->id])->update(['score' => 83]);

        app(RankService::class)->recalculateRanks();

        $this->assertSame(1, $highVolume->fresh()->rank);
        $this->assertSame(2, $lowVolume->fresh()->rank);
    }
}
