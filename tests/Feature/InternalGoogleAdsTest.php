<?php

namespace Tests\Feature;

use App\Models\GoogleAdsAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InternalGoogleAdsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'operator']);

        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
        ]);

        $this->account = GoogleAdsAccount::query()->create([
            'customer_id' => '1234567890',
            'name' => 'FlowHub Ads Account',
            'refresh_token' => 'mock-refresh-token',
            'status' => 'linked',
        ]);

        Cache::put('google-ads-token-'.$this->account->id, 'mock-access-token', 3600);
    }

    public function test_unauthenticated_requests_are_blocked(): void
    {
        $response = $this->postJson('/internal/google-ads/campaigns', [
            'name' => 'Search Campaign',
            'budget_amount_micros' => 10000000,
        ]);

        $response->assertUnauthorized();
    }

    public function test_post_campaigns_creates_search_campaign(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaignBudgets/88888']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaigns/99999']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Alpha Boost Campaign',
            'budget_amount_micros' => 10000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'campaign_id' => '99999',
                    'campaign_resource' => 'customers/1234567890/campaigns/99999',
                    'budget_resource' => 'customers/1234567890/campaignBudgets/88888',
                ],
            ]);
    }

    public function test_post_campaigns_with_negative_keywords_adds_negatives(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaignBudgets/88888']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaigns/99999']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~201'],
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~202'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Alpha Boost Campaign with Negatives',
            'budget_amount_micros' => 10000000,
            'bidding_strategy' => 'MANUAL_CPC',
            'negative_keywords' => ['free', 'coupon'],
            'geos' => ['US', 'CA'],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'campaign_id' => '99999',
                    'negatives_added' => 2,
                ],
            ]);
    }

    public function test_post_campaigns_with_full_expanded_parameters_creates_complete_search_campaign(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaignBudgets/88888']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaigns/99999']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~101'],
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~102'],
                ],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroups:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/adGroups/77777']],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~201'],
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~202'],
                ],
            ], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupAds:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/adGroupAds/77777~55555']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Fimavyn Blood Sugar Formula - Search',
            'budget_amount_micros' => 20000000,
            'bidding_strategy' => 'MANUAL_CPC',
            'target_search_network' => true,
            'target_content_network' => false,
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'language' => 'en',
            'geos' => ['US', 'CA'],
            'negative_keywords' => ['free', 'coupon'],
            'ad_group_name' => 'Fimavyn - Exact Match',
            'cpc_bid_micros' => 2500000,
            'keywords' => ['fimavyn buy', 'fimavyn official'],
            'keyword_match_type' => 'EXACT',
            'final_url' => 'https://example.com/fimavyn-tsl',
            'headlines' => ['Official Store Direct', '100% Authentic Formula', 'Save On Multi-Packs Today'],
            'descriptions' => [
                'Order direct from the official distributor. Secure 256-bit checkout with fast shipping.',
                'Claim your special bundle discount today. Backed by a full 60-day money-back guarantee.',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'campaign_id' => '99999',
                    'ad_group_id' => '77777',
                    'geos_added' => 2,
                    'language_added' => true,
                    'negatives_added' => 2,
                    'keywords_added' => 2,
                    'rsa_created' => true,
                ],
            ]);

        $this->assertDatabaseHas('google_ads_campaigns', [
            'campaign_id' => '99999',
            'name' => 'Fimavyn Blood Sugar Formula - Search',
            'status' => 'PAUSED',
            'budget_amount_micros' => 20000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);
    }

    public function test_post_campaigns_fails_when_no_google_ads_account_is_linked(): void
    {
        GoogleAdsAccount::query()->delete();

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns', [
            'name' => 'Alpha Boost Campaign',
            'budget_amount_micros' => 10000000,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'No linked Google Ads account found. Please link an account in Google Ads settings first.',
            ]);
    }

    public function test_post_ad_groups_creates_ad_group(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroups:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/adGroups/77777']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns/99999/ad-groups', [
            'name' => 'Search Ad Group Alpha',
            'cpc_bid_micros' => 1500000,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'ad_group_id' => '77777',
                    'resource_name' => 'customers/1234567890/adGroups/77777',
                ],
            ]);
    }

    public function test_post_keywords_adds_keywords_to_ad_group(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~101'],
                    ['resourceName' => 'customers/1234567890/adGroupCriteria/77777~102'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/ad-groups/77777/keywords', [
            'keywords' => ['alpha boost buy', 'alpha boost official'],
            'match_type' => 'EXACT',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'resource_names' => [
                        'customers/1234567890/adGroupCriteria/77777~101',
                        'customers/1234567890/adGroupCriteria/77777~102',
                    ],
                    'count' => 2,
                ],
            ]);
    }

    public function test_post_negative_keywords_adds_campaign_negatives(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => 'customers/1234567890/campaignCriteria/99999~201'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/campaigns/99999/negative-keywords', [
            'keywords' => ['free', 'cheap'],
            'match_type' => 'BROAD',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'count' => 1,
                ],
            ]);
    }

    public function test_post_responsive_search_ads_creates_rsa(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/adGroupAds:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/adGroupAds/77777~55555']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/ad-groups/77777/responsive-search-ads', [
            'headlines' => ['Official Store', 'Buy Direct and Save', 'High Potency Quality'],
            'descriptions' => ['Order now direct from the official distributor.', '100% money back guarantee for 60 days.'],
            'final_url' => 'https://example.com/alpha',
            'path1' => 'official',
            'path2' => 'store',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'ad_id' => '55555',
                    'resource_name' => 'customers/1234567890/adGroupAds/77777~55555',
                ],
            ]);
    }

    public function test_post_responsive_search_ads_validates_input(): void
    {
        // Too few headlines (only 2)
        $response = $this->actingAs($this->user)->postJson('/internal/google-ads/ad-groups/77777/responsive-search-ads', [
            'headlines' => ['Only One', 'Only Two'],
            'descriptions' => ['Desc 1', 'Desc 2'],
            'final_url' => 'https://example.com/alpha',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['headlines']);

        // Oversized headline (> 30 characters)
        $response2 = $this->actingAs($this->user)->postJson('/internal/google-ads/ad-groups/77777/responsive-search-ads', [
            'headlines' => ['Headline 1', 'Headline 2', 'This headline is much longer than thirty characters allowed'],
            'descriptions' => ['Desc 1', 'Desc 2'],
            'final_url' => 'https://example.com/alpha',
        ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['headlines.2']);
    }

    public function test_patch_campaign_status_and_confirmation_guard(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaigns:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaigns/99999']],
            ], 200),
        ]);

        // Enable campaign
        $response = $this->actingAs($this->user)->patchJson('/internal/google-ads/campaigns/99999/status', [
            'status' => 'ENABLED',
        ]);
        $response->assertOk()->assertJson(['ok' => true]);

        // Remove without confirm: false
        $response2 = $this->actingAs($this->user)->patchJson('/internal/google-ads/campaigns/99999/status', [
            'status' => 'REMOVED',
            'confirm' => false,
        ]);
        $response2->assertStatus(422)->assertJson(['ok' => false]);

        // Remove with confirm: true
        $response3 = $this->actingAs($this->user)->patchJson('/internal/google-ads/campaigns/99999/status', [
            'status' => 'REMOVED',
            'confirm' => true,
        ]);
        $response3->assertOk()->assertJson(['ok' => true]);
    }

    public function test_patch_budget_updates_amount(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignBudgets:mutate' => Http::response([
                'results' => [['resourceName' => 'customers/1234567890/campaignBudgets/88888']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->patchJson('/internal/google-ads/budgets/88888', [
            'amount_micros' => 20000000,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_get_listings_endpoints(): void
    {
        Http::fake([
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::response([
                'results' => [
                    [
                        'adGroup' => [
                            'id' => '77777',
                            'name' => 'Search Ad Group Alpha',
                            'status' => 'ENABLED',
                            'type' => 'SEARCH_STANDARD',
                            'cpcBidMicros' => 1500000,
                            'resourceName' => 'customers/1234567890/adGroups/77777',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->getJson('/internal/google-ads/campaigns/99999/ad-groups');
        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    [
                        'id' => '77777',
                        'name' => 'Search Ad Group Alpha',
                    ],
                ],
            ]);
    }
}
