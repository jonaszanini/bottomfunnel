<?php

namespace Tests\Feature;

use App\Enums\BrandBiddingStatus;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\TrendsTrend;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\PotentialScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_perfect_product_scores_high(): void
    {
        $product = WatchedProduct::factory()->create([
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'bof_homonym' => false,
            'trends_7d' => 45.0,
            'trends_30d' => 50.0,
            'trends_90d' => 48.0,
            'trends_trend' => TrendsTrend::Rising,
            'payout_amount' => 120.00,
            'payout_currency' => 'USD',
            'payout_model' => PayoutModel::CpaFixed,
            'page_type' => 'TSL',
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'geos' => ['US', 'CA', 'AU'],
            'advertiser_count' => 1,
            'approval_required' => false,
            'cancel_rate' => 5.0,
            'listing_status' => ListingStatus::Active,
            'restricted_category' => false,
        ]);

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
            'payout_amount' => 100.00,
        ]);

        $result = $this->scoreService->score($product);

        $this->assertLessThanOrEqual(20, $result['score']);
        $this->assertEquals(0, $result['breakdown']['demand']);
        $this->assertTrue($result['flags']['dead_post_peak'] ?? false);
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
}
