<?php

namespace Tests\Feature;

use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\Page;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrackPageCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $account;

    private GoogleAdsCampaign $campaignA;

    private GoogleAdsCampaign $campaignB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'trackmanager',
        ]);

        $this->account = GoogleAdsAccount::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Growth Scale Account',
            'customer_id' => '1112223333',
            'refresh_token' => 'mock-refresh-token',
            'status' => 'ACTIVE',
            'is_default' => true,
        ]);

        $this->campaignA = GoogleAdsCampaign::query()->create([
            'user_id' => $this->user->id,
            'google_ads_account_id' => $this->account->id,
            'customer_id' => '1112223333',
            'campaign_id' => '90001',
            'name' => 'Alpha Cleanse - US Scale',
            'status' => 'ENABLED',
            'budget_amount_micros' => 15000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);

        $this->campaignB = GoogleAdsCampaign::query()->create([
            'user_id' => $this->user->id,
            'google_ads_account_id' => $this->account->id,
            'customer_id' => '1112223333',
            'campaign_id' => '90002',
            'name' => 'Alpha Cleanse - CA Flash',
            'status' => 'PAUSED',
            'budget_amount_micros' => 10000000,
            'bidding_strategy' => 'MANUAL_CPC',
        ]);
    }

    public function test_creating_page_in_track_context_sets_track_id(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Keto Fuel Peak',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test&vendor=ketofuel',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001'],
            'google_ads_account_id' => $this->account->id,
        ]);

        $response = $this->actingAs($this->user)->post('/pages', [
            'name' => 'Keto Fuel Flash Sale Presell v1',
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'page_type' => 'flash_sale',
            'language' => 'en',
            'html' => '<!DOCTYPE html><html><body>Special Keto Offer</body></html>',
            'variation' => ['headline' => 'Keto Fuel 50% Off'],
        ]);

        $response->assertRedirect('/pages');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('pages', [
            'name' => 'Keto Fuel Flash Sale Presell v1',
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'page_type' => 'flash_sale',
        ]);
    }

    public function test_user_can_assign_page_to_campaign(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Alpha Cleanse',
            'platform' => 'maxweb',
            'affiliate_url' => 'https://network.example.com/aff_c?offer_id=55',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001', '90002'],
            'google_ads_account_id' => $this->account->id,
        ]);

        $page = Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => null,
            'name' => 'Cleanse Lander v1',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Cleanse</html>',
            'slug' => 'cleanse-lander-v1',
        ]);

        // Assign to campaign A
        $response = $this->actingAs($this->user)
            ->from("/tracks/{$track->id}")
            ->post("/pages/{$page->id}/assign-campaign", [
                'campaign_id' => '90001',
            ]);

        $response->assertRedirect("/tracks/{$track->id}");
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'campaign_id' => '90001',
        ]);
    }

    public function test_user_can_unassign_page_from_campaign(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Alpha Cleanse',
            'platform' => 'maxweb',
            'affiliate_url' => 'https://network.example.com/aff_c?offer_id=55',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001'],
        ]);

        $page = Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'name' => 'Cleanse Lander v1',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Cleanse</html>',
            'slug' => 'cleanse-lander-v1',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/pages/{$page->id}/assign-campaign", [
                'campaign_id' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'campaign_id' => null,
        ]);
    }

    public function test_track_show_page_displays_linked_pages_and_assigned_campaign(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Alpha Cleanse',
            'platform' => 'maxweb',
            'affiliate_url' => 'https://network.example.com/aff_c?offer_id=55',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001', '90002'],
            'google_ads_account_id' => $this->account->id,
        ]);

        $page1 = Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'name' => 'Cleanse Main Lander',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Lander 1</html>',
            'slug' => 'cleanse-main-lander',
        ]);

        $page2 = Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => null,
            'name' => 'Cleanse Backup Lander',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Lander 2</html>',
            'slug' => 'cleanse-backup-lander',
        ]);

        // Unrelated page belonging to another track
        $otherTrack = Track::query()->create([
            'product_name' => 'Other Offer',
            'platform' => 'digistore24',
            'affiliate_url' => 'https://other.example.com',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);
        Page::query()->create([
            'track_id' => $otherTrack->id,
            'name' => 'Other Lander',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Other</html>',
            'slug' => 'other-lander',
        ]);

        $response = $this->actingAs($this->user)->get("/tracks/{$track->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Tracks/Show')
                ->has('pages', 2)
                ->where('pages.0.id', $page1->id)
                ->where('pages.0.name', 'Cleanse Main Lander')
                ->where('pages.0.campaign_id', '90001')
                ->where('pages.0.campaign.name', 'Alpha Cleanse - US Scale')
                ->where('pages.0.campaign.account_name', 'Growth Scale Account')
                ->where('pages.1.id', $page2->id)
                ->where('pages.1.campaign_id', null)
                ->has('track_campaigns', 2)
                ->where('track_campaigns', fn ($camps) => collect($camps)->contains('campaign_id', '90001')
                    && collect($camps)->contains('campaign_id', '90002')
                    && collect($camps)->contains('account_name', 'Growth Scale Account'))
            );
    }

    public function test_google_ads_index_displays_assigned_pages_for_campaign(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Alpha Cleanse',
            'platform' => 'maxweb',
            'affiliate_url' => 'https://network.example.com/aff_c?offer_id=55',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001'],
        ]);

        Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'name' => 'Scale Lander Assigned',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Scale</html>',
            'slug' => 'scale-lander-assigned',
        ]);

        $response = $this->actingAs($this->user)->get('/google-ads');

        $response->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Google/Index')
                ->has('campaigns', 2)
                ->where('campaigns', fn ($campaigns) => collect($campaigns)->contains(function ($camp) {
                    return $camp['campaign_id'] === '90001'
                        && ! empty($camp['pages'])
                        && $camp['pages'][0]['name'] === 'Scale Lander Assigned'
                        && $camp['pages'][0]['product_name'] === 'Alpha Cleanse';
                }))
            );
    }

    public function test_pages_index_displays_track_and_campaign_details(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Alpha Cleanse',
            'platform' => 'maxweb',
            'affiliate_url' => 'https://network.example.com/aff_c?offer_id=55',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
            'campaign_ids' => ['90001'],
        ]);

        Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'name' => 'Alpha Presell Flash',
            'language' => 'en',
            'page_type' => 'presell',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Presell</html>',
            'slug' => 'alpha-presell-flash',
        ]);

        $response = $this->actingAs($this->user)->get('/pages');

        $response->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Pages/Index')
                ->has('pages', 1)
                ->where('pages.0.track_id', $track->id)
                ->where('pages.0.track_product_name', 'Alpha Cleanse')
                ->where('pages.0.campaign_id', '90001')
                ->where('pages.0.campaign_name', 'Alpha Cleanse - US Scale')
                ->where('pages.0.campaign_account_name', 'Growth Scale Account')
            );
    }

    public function test_track_create_form_provides_prelaunch_checklist_props(): void
    {
        $response = $this->actingAs($this->user)->get('/tracks/create');

        $response->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Tracks/Form')
                ->where('track', null)
                ->has('accounts')
                ->where('pregenerated_secret', fn ($secret) => is_string($secret) && strlen($secret) === 48)
                ->where('postback_base_url', fn ($url) => str_contains($url, '/hooks'))
            );
    }

    public function test_track_edit_form_provides_prelaunch_checklist_props_with_pages_and_postback(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Keto Peak Scale',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test&vendor=ketopeak',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'language' => 'en',
            'google_ads_account_id' => $this->account->id,
            'campaign_ids' => ['90001'],
        ]);

        Page::query()->create([
            'track_id' => $track->id,
            'campaign_id' => '90001',
            'name' => 'Lander 1',
            'language' => 'en',
            'page_type' => 'review_robust',
            'structure_json' => ['blocks' => []],
            'injected_script' => '',
            'html' => '<html>Test</html>',
        ]);

        $response = $this->actingAs($this->user)->get("/tracks/{$track->id}/edit");

        $response->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Tracks/Form')
                ->where('track.id', $track->id)
                ->where('track.pages_count', 1)
                ->where('track.postback_url', $track->postbackUrl())
                ->where('track.postback_secret', $track->postback_secret)
                ->where('postback_base_url', fn ($url) => str_contains($url, '/hooks'))
            );
    }
}
