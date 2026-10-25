<?php

namespace Tests\Feature;

use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoogleAdsMultiAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $accountA;

    private GoogleAdsAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => 'test-dev-token',
            'flowhub.google_ads.version' => 'v22',
        ]);

        $this->user = User::factory()->create([
            'username' => 'operator',
        ]);

        $this->accountA = GoogleAdsAccount::query()->create([
            'customer_id' => '1111111111',
            'name' => 'Agency Main Account',
            'refresh_token' => 'refresh-token-a',
            'status' => 'linked',
            'is_default' => true,
        ]);

        $this->accountB = GoogleAdsAccount::query()->create([
            'customer_id' => '2222222222',
            'login_customer_id' => '9999999999',
            'name' => 'Secondary Brand Account',
            'refresh_token' => 'refresh-token-b',
            'status' => 'linked',
            'is_default' => false,
        ]);

        Cache::put('google-ads-token-'.$this->accountA->id, 'mock-access-token-a', 3600);
        Cache::put('google-ads-token-'.$this->accountB->id, 'mock-access-token-b', 3600);
    }

    public function test_google_ads_index_page_receives_accounts_with_expected_fields(): void
    {
        // Link a track to Account A and a campaign to Account B
        $action = $this->accountA->conversionActions()->create([
            'resource_name' => 'customers/1111111111/conversionActions/101',
            'external_id' => '101',
            'name' => 'Sale Action A',
        ]);

        Track::query()->create([
            'product_name' => 'Keto Boost',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.test/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->accountA->id,
            'conversion_action_id' => $action->id,
            'campaign_ids' => ['camp-1'],
        ]);

        GoogleAdsCampaign::query()->create([
            'google_ads_account_id' => $this->accountB->id,
            'customer_id' => $this->accountB->customer_id,
            'campaign_id' => 'camp-999',
            'name' => 'Brand Campaign B',
            'status' => 'PAUSED',
            'budget_amount_micros' => 15000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        $response = $this->actingAs($this->user)->get('/google-ads');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Google/Index')
            ->has('accounts', 2)
            ->where('accounts.0.id', $this->accountA->id)
            ->where('accounts.0.name', 'Agency Main Account')
            ->where('accounts.0.customer_id', '1111111111')
            ->where('accounts.0.is_default', true)
            ->where('accounts.0.tracks_count', 1)
            ->where('accounts.1.id', $this->accountB->id)
            ->where('accounts.1.name', 'Secondary Brand Account')
            ->where('accounts.1.customer_id', '2222222222')
            ->where('accounts.1.login_customer_id', '9999999999')
            ->where('accounts.1.is_default', false)
            ->where('accounts.1.campaigns_count', 1)
            ->has('campaigns', 1)
            ->where('campaigns.0.campaign_id', 'camp-999')
            ->where('campaigns.0.account.id', $this->accountB->id)
            ->where('campaigns.0.account.name', 'Secondary Brand Account')
            ->where('campaigns.0.account.customer_id', '2222222222')
            ->where('default_account_id', $this->accountA->id)
        );
    }

    public function test_setting_default_account_updates_default_state(): void
    {
        $this->assertTrue($this->accountA->fresh()->is_default);
        $this->assertFalse($this->accountB->fresh()->is_default);

        $response = $this->actingAs($this->user)->post("/google-ads/accounts/{$this->accountB->id}/default");

        $response->assertRedirect();
        $this->assertFalse($this->accountA->fresh()->is_default);
        $this->assertTrue($this->accountB->fresh()->is_default);
        $this->assertEquals($this->accountB->id, session('default_google_ads_account_id'));
        $this->assertEquals($this->accountB->id, $this->user->fresh()->default_google_ads_account_id);
    }

    public function test_creating_track_with_selected_account_persists_relationship(): void
    {
        $action = $this->accountB->conversionActions()->create([
            'resource_name' => 'customers/2222222222/conversionActions/202',
            'external_id' => '202',
            'name' => 'Offline Purchase B',
        ]);

        $response = $this->actingAs($this->user)->post('/tracks', [
            'product_name' => 'Brain Focus Pro',
            'platform' => 'buygoods',
            'affiliate_url' => 'https://example.com/hop?aff=1',
            'page_type' => 'review_robust',
            'strategy' => 'manual_cpc',
            'typical_commission_cad' => 75.50,
            'google_ads_account_id' => $this->accountB->id,
            'conversion_action_id' => $action->id,
            'conversion_mode' => 'existing',
            'campaign_ids' => ['888123'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tracks', [
            'product_name' => 'Brain Focus Pro',
            'google_ads_account_id' => $this->accountB->id,
            'conversion_action_id' => $action->id,
        ]);

        $track = Track::query()->where('product_name', 'Brain Focus Pro')->firstOrFail();
        $this->assertEquals($this->accountB->id, $track->account->id);
        $this->assertEquals('Secondary Brand Account', $track->account->name);
        $this->assertEquals('2222222222', $track->account->customer_id);
    }

    public function test_track_index_and_show_expose_account_ownership(): void
    {
        $action = $this->accountB->conversionActions()->create([
            'resource_name' => 'customers/2222222222/conversionActions/202',
            'external_id' => '202',
            'name' => 'Offline Purchase B',
        ]);

        $track = Track::query()->create([
            'product_name' => 'Brain Focus Pro',
            'platform' => 'buygoods',
            'affiliate_url' => 'https://example.com/hop?aff=1',
            'page_type' => 'advertorial',
            'strategy' => 'manual_cpc',
            'google_ads_account_id' => $this->accountB->id,
            'conversion_action_id' => $action->id,
            'campaign_ids' => ['888123'],
        ]);

        // Index
        $indexResponse = $this->actingAs($this->user)->get('/tracks');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn (Assert $page) => $page
            ->component('Tracks/Index')
            ->where('tracks.0.account.id', $this->accountB->id)
            ->where('tracks.0.account.name', 'Secondary Brand Account')
            ->where('tracks.0.account.customer_id', '2222222222')
        );

        // Show
        $showResponse = $this->actingAs($this->user)->get("/tracks/{$track->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn (Assert $page) => $page
            ->component('Tracks/Show')
            ->where('track.account.id', $this->accountB->id)
            ->where('track.account.name', 'Secondary Brand Account')
            ->where('track.account.customer_id', '2222222222')
        );
    }

    public function test_campaign_creation_endpoint_respects_supplied_account_id(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/2222222222/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/2222222222/campaignBudgets/50505']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/2222222222/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/2222222222/campaigns/60606']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Dedicated Account B Campaign',
            'budget_amount_micros' => 12000000,
            'bidding_strategy' => 'MANUAL_CPC',
            'account_id' => $this->accountB->id,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'campaign_id' => '60606',
                    'campaign_resource' => 'customers/2222222222/campaigns/60606',
                    'account_id' => $this->accountB->id,
                    'customer_id' => '2222222222',
                    'account_name' => 'Secondary Brand Account',
                ],
            ]);

        // Verify request was sent to Account B customer (2222222222) and NOT Account A (1111111111)
        Http::assertSent(fn ($request) => str_contains($request->url(), 'customers/2222222222/campaigns:mutate'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'customers/1111111111/campaigns:mutate'));

        // Verify campaign is stored with account ownership
        $this->assertDatabaseHas('google_ads_campaigns', [
            'google_ads_account_id' => $this->accountB->id,
            'customer_id' => '2222222222',
            'campaign_id' => '60606',
            'name' => 'Dedicated Account B Campaign',
            'status' => 'PAUSED',
        ]);
    }

    public function test_campaign_creation_falls_back_to_default_account_when_no_account_supplied(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1111111111/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1111111111/campaignBudgets/30303']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1111111111/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1111111111/campaigns/40404']],
            ], 200),
        ]);

        // Account A is the default account
        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Default Account Campaign',
            'budget_amount_micros' => 10000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'campaign_id' => '40404',
                    'account_id' => $this->accountA->id,
                    'customer_id' => '1111111111',
                    'account_name' => 'Agency Main Account',
                ],
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'customers/1111111111/campaigns:mutate'));
        $this->assertDatabaseHas('google_ads_campaigns', [
            'google_ads_account_id' => $this->accountA->id,
            'campaign_id' => '40404',
        ]);
    }

    public function test_campaign_creation_fails_when_multiple_accounts_exist_and_no_default_set(): void
    {
        // Unset defaults
        GoogleAdsAccount::query()->update(['is_default' => false]);
        $this->user->update(['default_google_ads_account_id' => null]);
        session()->forget('default_google_ads_account_id');

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Ambiguous Campaign',
            'budget_amount_micros' => 10000000,
        ]);

        $response->assertStatus(422);
    }

    public function test_artisan_command_creates_campaign_for_specified_account(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/2222222222/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/2222222222/campaignBudgets/70707']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/2222222222/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/2222222222/campaigns/80808']],
            ], 200),
        ]);

        $this->artisan('google-ads:campaign', [
            'action' => 'create',
            '--account' => $this->accountB->id,
            '--name' => 'CLI Brand B Campaign',
            '--budget' => 10000000,
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'customers/2222222222/campaigns:mutate'));

        $this->assertDatabaseHas('google_ads_campaigns', [
            'google_ads_account_id' => $this->accountB->id,
            'customer_id' => '2222222222',
            'campaign_id' => '80808',
            'name' => 'CLI Brand B Campaign',
        ]);
    }

    public function test_artisan_command_falls_back_to_default_account(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1111111111/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1111111111/campaignBudgets/90909']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1111111111/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1111111111/campaigns/91919']],
            ], 200),
        ]);

        $this->artisan('google-ads:campaign', [
            'action' => 'create',
            '--name' => 'CLI Default Campaign',
            '--budget' => 10000000,
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'customers/1111111111/campaigns:mutate'));

        $this->assertDatabaseHas('google_ads_campaigns', [
            'google_ads_account_id' => $this->accountA->id,
            'customer_id' => '1111111111',
            'campaign_id' => '91919',
        ]);
    }

    public function test_conversion_action_tag_endpoint_returns_snippets(): void
    {
        $action = ConversionAction::query()->create([
            'google_ads_account_id' => $this->accountA->id,
            'external_id' => '12345',
            'name' => 'Webpage Purchase',
            'resource_name' => 'customers/1111111111/conversionActions/12345',
        ]);

        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1111111111/googleAds:search' => Http::response([
                'results' => [
                    [
                        'conversionAction' => [
                            'resourceName' => 'customers/1111111111/conversionActions/12345',
                            'tagSnippets' => [
                                [
                                    'type' => 'WEBPAGE',
                                    'pageFormat' => 'HTML',
                                    'globalSiteTag' => '<!-- Global site tag (gtag.js) -->',
                                    'eventSnippet' => '<!-- Event snippet -->',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/google-ads/accounts/{$this->accountA->id}/conversion-actions/{$action->id}/tag");

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'global_site_tag' => '<!-- Global site tag (gtag.js) -->',
                'event_snippet' => '<!-- Event snippet -->',
                'has_tag' => true,
                'action_name' => 'Webpage Purchase',
            ]);
    }

    public function test_conversion_tag_rejects_default_action_name(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/google-ads/accounts/{$this->accountA->id}/conversion-actions/default/tag");

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'message' => 'A valid conversion action is required.',
            ]);
    }
}
