<?php

namespace Tests\Feature;

use App\Enums\BrandBiddingStatus;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\TrendsTrend;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescoreWatchingProductsCommandTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_dry_run_prints_old_and_new_scores_and_writes_nothing(): void
    {
        $product = $this->makeQualityProduct([
            'search_volume_total' => 150,
            'search_volume_checked_at' => now(),
        ]);

        $original = $product->fresh();
        $originalScore = $original->score;
        $originalBreakdown = $original->score_breakdown;
        $originalFlags = $original->score_flags;
        $originalStatus = $original->status;
        $originalRank = $original->rank;
        $originalScoredAt = $original->scored_at?->toIso8601String();
        $originalUpdatedAt = $original->updated_at?->toIso8601String();

        $this->artisan('watching:rescore', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain((string) $product->id);

        $fresh = $product->fresh();

        $this->assertSame($originalScore, $fresh->score);
        $this->assertSame($originalBreakdown, $fresh->score_breakdown);
        $this->assertSame($originalFlags, $fresh->score_flags);
        $this->assertSame($originalStatus, $fresh->status);
        $this->assertSame($originalRank, $fresh->rank);
        $this->assertSame($originalScoredAt, $fresh->scored_at?->toIso8601String());
        $this->assertSame($originalUpdatedAt, $fresh->updated_at?->toIso8601String());
        $this->assertSame(WatchedProductStatus::Watch, $fresh->status);
    }

    public function test_rescore_command_updates_score_fields_without_changing_status(): void
    {
        $product = $this->makeQualityProduct([
            'search_volume_total' => null,
            'search_volume_checked_at' => null,
        ]);

        WatchedProduct::query()->whereKey($product->id)->update([
            'score' => 12,
            'score_breakdown' => ['q' => 12],
            'score_flags' => ['stale' => true],
        ]);

        $this->artisan('watching:rescore')->assertSuccessful();

        $fresh = $product->fresh();

        $this->assertSame(WatchedProductStatus::Watch, $fresh->status);
        $this->assertSame(69, $fresh->score);
        $this->assertSame(100, $fresh->score_breakdown['q']);
        $this->assertTrue($fresh->score_flags['volume_no_data'] ?? false);
        $this->assertArrayNotHasKey('stale', $fresh->score_flags);
        $this->assertNotNull($fresh->rank);
    }
}
