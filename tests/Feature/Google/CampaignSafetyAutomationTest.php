<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Enums\ListingStatus;
use App\Enums\WatchedProductStatus;
use App\Models\AdSpend;
use App\Models\CampaignAlert;
use App\Models\Conversion;
use App\Models\FxRate;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\Track;
use App\Models\Visit;
use App\Models\WatchedProduct;
use App\Services\Google\GoogleAdsClient;
use App\Services\WatchingProducts\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CampaignSafetyAutomationTest extends TestCase
{
    use RefreshDatabase;

    private GoogleAdsAccount $account;

    private MockInterface&GoogleAdsClient $googleAdsMock;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flowhub.google_ads.client_id' => 'mock-client-id',
            'flowhub.google_ads.client_secret' => 'mock-client-secret',
            'flowhub.google_ads.version' => 'v22',
            'flowhub.auto_pause_enabled' => true,
            'watching.auto_pause_enabled' => true,
            'flowhub.safety_rules.bleeder.spend_payout_multiplier' => 1.5,
            'flowhub.safety_rules.bleeder.window_days' => 7,
            'flowhub.safety_rules.bleeder.min_clicks' => 20,
            'flowhub.safety_rules.bleeder.postback_buffer_hours' => 3,
            'flowhub.safety_rules.listing_down.consecutive_failures_required' => 2,
        ]);

        $this->account = GoogleAdsAccount::query()->create([
            'customer_id' => '9876543210',
            'name' => 'Automated Safety Test Account',
            'refresh_token' => 'mock-token',
        ]);

        Cache::put('google-ads-token-'.$this->account->id, 'mock-access-token', 3600);

        $this->googleAdsMock = Mockery::mock(GoogleAdsClient::class);
        $this->app->instance(GoogleAdsClient::class, $this->googleAdsMock);
    }

    private function createTrackAndCampaign(string $productName, string $campaignId = '1122334455'): array
    {
        $track = Track::query()->create([
            'product_name' => $productName,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test&vendor=safety',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'google_ads_account_id' => $this->account->id,
            'campaign_ids' => [$campaignId],
        ]);

        $campaign = GoogleAdsCampaign::query()->create([
            'google_ads_account_id' => $this->account->id,
            'customer_id' => $this->account->customer_id,
            'campaign_id' => $campaignId,
            'name' => "{$productName} - Search Campaign",
            'status' => 'ENABLED',
            'budget_amount_micros' => 50000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        return [$track, $campaign];
    }

    private function createMatureVisits(Track $track, int $count = 25): void
    {
        for ($i = 0; $i < $count; $i++) {
            Visit::query()->create([
                'session_id' => 'sess-'.$track->id.'-'.$i.'-'.uniqid(),
                'track_id' => $track->id,
                'page_type' => 'presell',
                'ip_address' => '192.168.1.1',
                // Landed 24 hours ago (well outside the 3-hour attribution lag buffer window)
                'landed_at' => now()->subHours(24)->subMinutes($i * 5),
            ]);
        }
    }

    // =========================================================================
    // Scenario 1: Bleeder Campaign Detection and CAD$ Normalization
    // =========================================================================

    public function test_bleeder_campaign_exceeding_multiplier_with_zero_conversions_is_paused(): void
    {
        // 1. Seed FxRate: USD 1.00 = CAD 1.25
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.25000000,
            'source' => 'test_seed',
        ]);

        // WatchedProduct payout: USD 80.00 -> 80 * 1.25 = CAD$ 100.00
        $product = WatchedProduct::query()->create([
            'name' => 'Titan Testo Booster',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 80.00,
            'payout_currency' => 'USD',
            'listing_url' => 'https://safe-target-offer.com/titan',
            'listing_status' => ListingStatus::Active,
        ]);

        $payoutCad = Money::make($product->payout_amount, $product->payout_currency)->toCad();
        $this->assertEquals(100.00, $payoutCad);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '2001001');

        // Record 25 clicks matured beyond 3-hour postback lag buffer
        $this->createMatureVisits($track, 25);

        // Record AdSpend of CAD$ 160.00 (threshold is 100.00 * 1.5 = CAD$ 150.00)
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 160.00,
            'note' => 'Google Ads spend',
        ]);

        // Zero conversion records exist
        $this->assertSame(0, Conversion::query()->where('track_id', $track->id)->count());

        // Assert GoogleAdsClient::updateCampaignStatus is called to pause the campaign
        $this->googleAdsMock
            ->shouldReceive('updateCampaignStatus')
            ->once()
            ->with(
                Mockery::on(fn (GoogleAdsAccount $acc): bool => $acc->id === $this->account->id),
                '2001001',
                'PAUSED'
            );

        // Run CheckBleederCampaigns
        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful()
            ->expectsOutputToContain('Total bleeder tracks detected: 1');

        // Assert campaign status transitioned to PAUSED
        $campaign->refresh();
        $this->assertSame('PAUSED', $campaign->status);
        $this->assertTrue($campaign->isPaused());

        // Assert CampaignAlert record created
        $alert = CampaignAlert::query()
            ->where('campaign_id', '2001001')
            ->where('type', 'bleeder')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('paused', $alert->action_taken);
        $this->assertSame($track->id, $alert->track_id);
        $this->assertEquals(160.00, (float) $alert->spend_cad);
        $this->assertEquals(100.00, (float) $alert->payout_cad);
        $this->assertEquals(150.00, (float) $alert->threshold_cad);
        $this->assertSame(0, $alert->conversions_count);
    }

    public function test_bleeder_campaign_is_not_paused_when_valid_conversion_exists(): void
    {
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.25000000,
            'source' => 'test_seed',
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Zenith Probiotic',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 80.00,
            'payout_currency' => 'USD',
            'listing_url' => 'https://safe-target-offer.com/zenith',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '2001002');
        $this->createMatureVisits($track, 25);

        // Spend CAD$ 160.00 exceeds 150.00 threshold
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 160.00,
        ]);

        // BUT a valid conversion exists
        Conversion::query()->create([
            'track_id' => $track->id,
            'order_id' => 'ORD-12345',
            'amount' => 80.00,
            'currency' => 'USD',
            'commission_value_cad' => 100.00,
            'status' => 'matched',
            'raw_payload' => '{}',
            'created_at' => now()->subDays(2),
        ]);

        // GoogleAdsClient::updateCampaignStatus should NEVER be called
        $this->googleAdsMock->shouldReceive('updateCampaignStatus')->never();

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful();

        $campaign->refresh();
        $this->assertSame('ENABLED', $campaign->status);
        $this->assertFalse($campaign->isPaused());

        $this->assertSame(0, CampaignAlert::query()->where('campaign_id', '2001002')->count());
    }

    public function test_bleeder_campaign_is_not_paused_when_clicks_below_threshold(): void
    {
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.25000000,
            'source' => 'test_seed',
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Keto Gummies 500',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 80.00,
            'payout_currency' => 'USD',
            'listing_url' => 'https://safe-target-offer.com/gummies',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '2001003');

        // Only 5 clicks (below the 20 minimum clicks threshold)
        $this->createMatureVisits($track, 5);

        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 160.00,
        ]);

        $this->googleAdsMock->shouldReceive('updateCampaignStatus')->never();

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful();

        $campaign->refresh();
        $this->assertSame('ENABLED', $campaign->status);
        $this->assertFalse($campaign->isPaused());

        $this->assertSame(0, CampaignAlert::query()->where('campaign_id', '2001003')->count());
    }

    // =========================================================================
    // Scenario 2: Listing Down Multi Step Confirmation
    // =========================================================================

    public function test_listing_down_does_not_pause_campaign_on_single_transient_error(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'NeuroFocus Brain Supplement',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://safe-target-offer.com/neuro-focus',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '3001001');

        // Mock HTTP response with a transient 503 error
        Http::fake([
            'https://safe-target-offer.com/neuro-focus*' => Http::response('503 Service Unavailable', 503),
        ]);

        // GoogleAdsClient pause must NOT be called on transient error 1
        $this->googleAdsMock->shouldReceive('updateCampaignStatus')->never();

        $this->artisan('campaigns:check-listing-down')
            ->assertSuccessful()
            ->expectsOutputToContain('Transient errors detected on 1 listing(s). Multi-step confirmation held campaign pauses.');

        // Assert campaign remains active
        $campaign->refresh();
        $this->assertSame('ENABLED', $campaign->status);
        $this->assertFalse($campaign->isPaused());

        // Product listing status remains Active while under confirmation threshold
        $product->refresh();
        $this->assertSame(ListingStatus::Active, $product->listing_status);

        // Assert cache recorded 1 failure
        $cacheKey = "listing_transient_failures_{$product->id}";
        $this->assertSame(1, (int) Cache::get($cacheKey));

        // No paused alert created
        $this->assertSame(0, CampaignAlert::query()->where('campaign_id', '3001001')->count());
    }

    public function test_listing_down_pauses_campaign_after_consecutive_transient_failures(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'Cardio Shield Formula',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://safe-target-offer.com/cardio-shield',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '3001002');

        Http::fake([
            'https://safe-target-offer.com/cardio-shield*' => Http::response('503 Service Unavailable', 503),
        ]);

        // Run 1: First transient failure
        $this->googleAdsMock->shouldReceive('updateCampaignStatus')->never();
        $this->artisan('campaigns:check-listing-down')->assertSuccessful();

        $campaign->refresh();
        $this->assertSame('ENABLED', $campaign->status);

        // Run 2: Second consecutive failure (reaches consecutive_failures_required = 2)
        // Now GoogleAdsClient should be called to pause the campaign!
        $this->googleAdsMock
            ->shouldReceive('updateCampaignStatus')
            ->once()
            ->with(
                Mockery::on(fn (GoogleAdsAccount $acc): bool => $acc->id === $this->account->id),
                '3001002',
                'PAUSED'
            );

        $this->artisan('campaigns:check-listing-down')
            ->assertSuccessful()
            ->expectsOutputToContain('Total campaigns paused: 1');

        $campaign->refresh();
        $this->assertSame('PAUSED', $campaign->status);
        $this->assertTrue($campaign->isPaused());

        $product->refresh();
        $this->assertSame(ListingStatus::Expired, $product->listing_status);

        $alert = CampaignAlert::query()
            ->where('campaign_id', '3001002')
            ->where('type', 'listing_down')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('paused', $alert->action_taken);
        $this->assertSame($product->id, $alert->product_id);
    }

    // =========================================================================
    // Scenario 3: CampaignAlert Idempotency (No Alert Spam)
    // =========================================================================

    public function test_check_bleeders_is_idempotent_and_does_not_create_duplicate_alerts(): void
    {
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.25000000,
            'source' => 'test_seed',
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Vision Clarity Pro',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 80.00,
            'payout_currency' => 'USD',
            'listing_url' => 'https://safe-target-offer.com/vision',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '4001001');
        $this->createMatureVisits($track, 25);

        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 160.00,
        ]);

        $this->googleAdsMock
            ->shouldReceive('updateCampaignStatus')
            ->twice()
            ->with(Mockery::any(), '4001001', 'PAUSED');

        // Run 1: First check creates alert
        $this->artisan('campaigns:check-bleeders')->assertSuccessful();

        $this->assertSame(
            1,
            CampaignAlert::query()->where('campaign_id', '4001001')->where('type', 'bleeder')->count()
        );

        // Run 2: Second check for the same failing campaign updates existing without duplicating
        $this->artisan('campaigns:check-bleeders')->assertSuccessful();

        $this->assertSame(
            1,
            CampaignAlert::query()->where('campaign_id', '4001001')->where('type', 'bleeder')->count()
        );
    }

    public function test_check_listing_down_is_idempotent_and_does_not_create_duplicate_alerts(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'Joint Relief Advance',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://safe-target-offer.com/joint-relief',
            'listing_status' => ListingStatus::Active,
        ]);

        [$track, $campaign] = $this->createTrackAndCampaign($product->name, '4001002');

        // Mock definitive expired listing page content matching ListingChecker::isExpiredHtml
        Http::fake([
            'https://safe-target-offer.com/joint-relief*' => Http::response('<html><body>This offer expired and the product is no longer available.</body></html>', 200),
        ]);

        $this->googleAdsMock
            ->shouldReceive('updateCampaignStatus')
            ->twice()
            ->with(Mockery::any(), '4001002', 'PAUSED');

        // Run 1
        $this->artisan('campaigns:check-listing-down')->assertSuccessful();

        $this->assertSame(
            1,
            CampaignAlert::query()->where('campaign_id', '4001002')->where('type', 'listing_down')->count()
        );

        // Run 2
        $this->artisan('campaigns:check-listing-down')->assertSuccessful();

        $this->assertSame(
            1,
            CampaignAlert::query()->where('campaign_id', '4001002')->where('type', 'listing_down')->count()
        );
    }
}
