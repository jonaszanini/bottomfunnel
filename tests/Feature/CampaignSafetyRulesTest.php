<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\WatchedProductStatus;
use App\Models\AdSpend;
use App\Models\CampaignAlert;
use App\Models\Conversion;
use App\Models\GoogleAdsAccount;
use App\Models\Track;
use App\Models\User;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignSafetyRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
            'flowhub.safety_rules.bleeder.spend_payout_multiplier' => 1.5,
            'flowhub.safety_rules.bleeder.window_days' => 7,
            'flowhub.safety_rules.bleeder.action' => 'alert',
            'watching.auto_pause_enabled' => false,
            'flowhub.auto_pause_enabled' => false,
        ]);
    }

    protected function createAccount(string $customerId = '1234567890'): GoogleAdsAccount
    {
        $account = GoogleAdsAccount::query()->create([
            'customer_id' => $customerId,
            'name' => 'Safety Test Account',
            'refresh_token' => 'mock-refresh-token',
        ]);

        Cache::put('google-ads-token-'.$account->id, 'mock-access-token', 3600);

        return $account;
    }

    public function test_listing_down_creates_alert_and_does_not_pause_campaign_by_default(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Keto Ultra',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?affiliate=test&vendor=ketoultra',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 60.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['111222333'],
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Keto Ultra',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/offer-expired-page',
            'listing_status' => ListingStatus::Active,
        ]);

        Http::fake([
            'https://example.com/offer-expired-page' => Http::response('<html><body>This offer expired and is no longer available.</body></html>', 200),
        ]);

        $this->artisan('campaigns:check-listing-down')
            ->assertSuccessful()
            ->expectsOutputToContain('Total safety alerts raised: 1');

        $this->assertEquals(ListingStatus::Expired, $product->fresh()->listing_status);

        // Verify alert was created in database with required fields
        $alert = CampaignAlert::query()->where('type', 'listing_down')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('Keto Ultra', $alert->product_name);
        $this->assertEquals('111222333', $alert->campaign_id);
        $this->assertEquals($track->id, $alert->track_id);
        $this->assertEquals('Listing unavailable', $alert->reason);
        $this->assertEquals('alert_raised', $alert->action_taken);
        $this->assertStringContainsString('Listing unavailable', $alert->message);

        // Assert NO pause request was sent to Google Ads API
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_listing_down_pauses_campaign_when_auto_pause_enabled_config_is_true(): void
    {
        config(['watching.auto_pause_enabled' => true, 'flowhub.auto_pause_enabled' => true]);

        $account = $this->createAccount('1234567890');

        Track::query()->create([
            'product_name' => 'Keto Ultra Pause',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=ketopause',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 60.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['111222333'],
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Keto Ultra Pause',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/offer-expired-page-2',
            'listing_status' => ListingStatus::Active,
        ]);

        Http::fake([
            'https://example.com/offer-expired-page-2' => Http::response('Offer expired', 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaigns/111222333'],
                ],
            ], 200),
        ]);

        $this->artisan('campaigns:check-listing-down')
            ->assertSuccessful()
            ->expectsOutputToContain('Total campaigns paused: 1');

        $this->assertEquals(ListingStatus::Expired, $product->fresh()->listing_status);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaigns:mutate')) {
                return false;
            }

            $op = $request['operations'][0]['update'] ?? [];

            return $op['status'] === 'PAUSED'
                && $op['resourceName'] === 'customers/1234567890/campaigns/111222333';
        });
    }

    public function test_listing_down_marks_expired_and_creates_alert_on_http_404(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Dead Link Offer',
            'platform' => 'buygoods',
            'affiliate_url' => 'https://buygoods.com/affiliate',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['444555666'],
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Dead Link Offer',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/404-landing-page',
            'listing_status' => ListingStatus::Active,
        ]);

        Http::fake([
            'https://example.com/404-landing-page' => Http::response('Not Found', 404),
        ]);

        $this->artisan('campaigns:check-listing-down', ['--product' => $product->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Total safety alerts raised: 1');

        $this->assertEquals(ListingStatus::Expired, $product->fresh()->listing_status);

        $alert = CampaignAlert::query()->where('type', 'listing_down')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('Dead Link Offer', $alert->product_name);
        $this->assertEquals('444555666', $alert->campaign_id);

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_listing_down_dry_run_mode_does_not_mutate_campaign(): void
    {
        $account = $this->createAccount('1234567890');

        Track::query()->create([
            'product_name' => 'Dry Run Product',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=dryrun',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 70.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['777888999'],
        ]);

        WatchedProduct::query()->create([
            'name' => 'Dry Run Product',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/expired-page',
            'listing_status' => ListingStatus::Active,
        ]);

        Http::fake([
            'https://example.com/expired-page' => Http::response('Offer expired', 200),
        ]);

        $this->artisan('campaigns:check-listing-down', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN MODE ENABLED]');

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_listing_down_does_not_alert_healthy_active_listing(): void
    {
        $account = $this->createAccount('1234567890');

        Track::query()->create([
            'product_name' => 'Healthy Product',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=healthy',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 80.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['999111222'],
        ]);

        WatchedProduct::query()->create([
            'name' => 'Healthy Product',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/healthy-lander',
            'listing_status' => ListingStatus::Active,
        ]);

        Http::fake([
            'https://example.com/healthy-lander' => Http::response('<html><body><h1>Welcome to Healthy Product</h1><a href="https://hop.clickbank.net/checkout">Buy Now</a></body></html>', 200),
        ]);

        $this->artisan('campaigns:check-listing-down')
            ->assertSuccessful()
            ->expectsOutputToContain('All checked listings are active. No alerts or pauses required.');

        $this->assertEquals(0, CampaignAlert::query()->count());

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_spend_without_conversion_bleeder_rule_creates_alert_and_does_not_pause_by_default(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Bleeder Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=bleeder',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00, // 1.5x payout threshold = 75.00 CAD
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['555666777'],
        ]);

        // Record spend of 80.00 CAD (> 75.00 CAD threshold) in past 3 days
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 80.00,
            'note' => 'Google Ads spend',
        ]);

        // Zero conversions recorded

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful()
            ->expectsOutputToContain('Total bleeder tracks detected: 1')
            ->expectsOutputToContain('alert_raised');

        // Assert alert was saved to database with all required fields
        $alert = CampaignAlert::query()->where('type', 'bleeder')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('Bleeder Offer', $alert->product_name);
        $this->assertEquals($track->id, $alert->track_id);
        $this->assertEquals('Spend without conversion', $alert->reason);
        $this->assertEquals(80.00, (float) $alert->spend_cad);
        $this->assertEquals(50.00, (float) $alert->payout_cad);
        $this->assertEquals(75.00, (float) $alert->threshold_cad);
        $this->assertEquals(7, $alert->window_days);
        $this->assertEquals(0, $alert->conversions_count);
        $this->assertEquals('alert_raised', $alert->action_taken);

        // Assert NO mutate request was sent to Google Ads API
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_spend_without_conversion_bleeder_rule_pauses_when_auto_pause_enabled_config_is_true(): void
    {
        config(['watching.auto_pause_enabled' => true, 'flowhub.auto_pause_enabled' => true]);

        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Bleeder Pause Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=bleederpause',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['555666777'],
        ]);

        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'amount_cad' => 80.00,
        ]);

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaigns/555666777'],
                ],
            ], 200),
        ]);

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful()
            ->expectsOutputToContain('Total bleeder tracks detected: 1');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaigns:mutate')) {
                return false;
            }

            $op = $request['operations'][0]['update'] ?? [];

            return $op['status'] === 'PAUSED'
                && $op['resourceName'] === 'customers/1234567890/campaigns/555666777';
        });
    }

    public function test_bleeder_rule_does_not_alert_if_conversions_exist_in_window(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Converting Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=converting',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['555666777'],
        ]);

        // Spend $90 CAD (> 1.5x $50)
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 90.00,
        ]);

        // 1 conversion exists in window
        Conversion::query()->create([
            'track_id' => $track->id,
            'order_id' => 'ORDER-12345',
            'amount' => 50.00,
            'currency' => 'CAD',
            'commission_value_cad' => 50.00,
            'status' => 'matched',
            'raw_payload' => '{}',
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful()
            ->expectsOutputToContain('All evaluated campaigns are healthy within spend thresholds.');

        $this->assertEquals(0, CampaignAlert::query()->where('type', 'bleeder')->count());

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_bleeder_rule_does_not_alert_if_spend_below_multiplier_threshold(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Under Threshold Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=under',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 60.00, // 1.5x = 90.00 CAD
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['333222111'],
        ]);

        // Spend 70.00 CAD (< 90.00 CAD threshold), zero conversions
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 70.00,
        ]);

        $this->artisan('campaigns:check-bleeders')
            ->assertSuccessful()
            ->expectsOutputToContain('All evaluated campaigns are healthy within spend thresholds.');

        $this->assertEquals(0, CampaignAlert::query()->where('type', 'bleeder')->count());

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_bleeder_rule_dry_run_mode_does_not_mutate(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Bleeder Dry Run',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=dryrun2',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 40.00, // 1.5x = 60.00 CAD
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['987654321'],
        ]);

        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 100.00,
        ]);

        $this->artisan('campaigns:check-bleeders', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN MODE ENABLED]');

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_bleeder_rule_suggest_mode_does_not_mutate_and_flags_suggest_pause(): void
    {
        $account = $this->createAccount('1234567890');

        $track = Track::query()->create([
            'product_name' => 'Bleeder Suggest Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=suggest',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['123123123'],
        ]);

        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 120.00,
        ]);

        $this->artisan('campaigns:check-bleeders', ['--suggest' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[SUGGESTION MODE ENABLED]')
            ->expectsOutputToContain('suggest_pause');

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_weekly_refresh_creates_alert_and_does_not_pause_campaign_by_default(): void
    {
        $account = $this->createAccount('1234567890');

        Track::query()->create([
            'product_name' => 'Weekly Refresh Product',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?vendor=refreshprod',
            'page_type' => 'review',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 50.00,
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['666777888'],
        ]);

        $product = WatchedProduct::query()->create([
            'name' => 'Weekly Refresh Product',
            'status' => WatchedProductStatus::Watch,
            'listing_url' => 'https://example.com/expired-listing',
            'listing_status' => ListingStatus::Active,
            'payout_amount' => 50.00,
            'payout_currency' => 'CAD',
        ]);

        Http::fake([
            'https://example.com/expired-listing' => Http::response('This offer expired', 200),
            'https://api.frankfurter.app/*' => Http::response(['rates' => ['CAD' => 1.35]], 200),
        ]);

        $this->artisan('watching:refresh', ['--no-serp' => true, '--product' => $product->id])
            ->assertSuccessful();

        $this->assertEquals(ListingStatus::Expired, $product->fresh()->listing_status);

        // Alert created in database
        $alert = CampaignAlert::query()->where('type', 'listing_down')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('Weekly Refresh Product', $alert->product_name);
        $this->assertEquals('666777888', $alert->campaign_id);

        // No mutation sent
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), 'campaigns:mutate');
        });
    }

    public function test_dashboard_displays_active_safety_alerts(): void
    {
        $user = User::factory()->create(['username' => 'owner']);

        CampaignAlert::query()->create([
            'type' => 'bleeder',
            'product_name' => 'Active Bleeder Prod',
            'reason' => 'Spend without conversion',
            'message' => 'Spend CA$150.00 exceeded threshold CA$75.00 with 0 conversions.',
            'spend_cad' => 150.00,
            'payout_cad' => 50.00,
            'threshold_cad' => 75.00,
            'window_days' => 7,
            'conversions_count' => 0,
            'action_taken' => 'alert_raised',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('report.safety_alerts', 1)
                ->where('report.safety_alerts.0.product_name', 'Active Bleeder Prod')
                ->where('report.safety_alerts.0.reason', 'Spend without conversion')
                ->where('report.safety_alerts.0.spend_cad', 150)
            );
    }
}
