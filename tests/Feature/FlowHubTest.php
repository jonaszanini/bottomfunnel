<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\Conversion;
use App\Models\GoogleAdsAccount;
use App\Models\Track;
use App\Models\User;
use App\Models\Visit;
use App\Services\Tracking\FraudScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_sign_in(): void
    {
        $user = User::factory()->create([
            'username' => 'owner',
            'password' => 'ChangeMe123!',
        ]);

        $this->post('/login', [
            'username' => 'owner',
            'password' => 'wrong',
        ])->assertSessionHasErrors('username');

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'ChangeMe123!',
        ])->assertRedirect('/');

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_postback_matches_converts_and_uploads_once(): void
    {
        [, $track, $visit] = $this->makeTrack();
        Http::fake([
            'www.bankofcanada.ca/*' => Http::response([
                'observations' => [[
                    'FXUSDCAD' => ['v' => '1.25000000'],
                ]],
            ]),
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'token-1',
                'expires_in' => 3600,
            ]),
            'datamanager.googleapis.com/*' => Http::response(['requestId' => 'req-1']),
        ]);

        $payload = http_build_query([
            'receipt' => 'ORDER-1',
            'accountAmount' => '20.00',
            'currency' => 'USD',
            'gclid' => 'gclid-1',
            'transactionType' => 'SALE',
        ]);

        $this->call('POST', '/hooks/clickbank/'.$track->postback_secret, [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $payload)->assertOk();

        $this->assertSame(1, Conversion::query()->count());
        $conversion = Conversion::query()->first();
        $this->assertSame('uploaded', $conversion->status);
        $this->assertSame('gclid', $conversion->match_source);
        $this->assertSame('25.00', $conversion->commission_value_cad);
        $this->assertSame($payload, $conversion->raw_payload);
        $this->assertTrue($visit->fresh()->converted);

        $this->call('POST', '/hooks/clickbank/'.$track->postback_secret, [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $payload)->assertOk();
        $this->assertSame(1, Conversion::query()->count());

        $refund = http_build_query([
            'receipt' => 'ORDER-1',
            'accountAmount' => '20.00',
            'currency' => 'USD',
            'gclid' => 'gclid-1',
            'transactionType' => 'RFND',
        ]);
        $this->call('POST', '/hooks/clickbank/'.$track->postback_secret, [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $refund)->assertOk();

        $conversion->refresh();
        $this->assertSame('refunded', $conversion->status);
        $this->assertSame('0.00', $conversion->commission_value_cad);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'events:ingest'));
    }

    public function test_unmatched_postback_can_be_attached_by_hand(): void
    {
        [$user, $track, $visit] = $this->makeTrack('digistore24');
        Http::fake([
            'www.bankofcanada.ca/*' => Http::response([
                'observations' => [[
                    'FXUSDCAD' => ['v' => '1.00000000'],
                ]],
            ]),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            'datamanager.googleapis.com/*' => Http::response(['requestId' => 'req-2']),
        ]);

        $this->post('/hooks/digistore24/'.$track->postback_secret, [
            'order_id' => 'DS-9',
            'amount_affiliate' => '8.00',
            'currency' => 'USD',
            'sid' => 'missing-session',
            'event' => 'on_payment',
        ])->assertOk();

        $conversion = Conversion::query()->first();
        $this->assertSame('unmatched', $conversion->status);

        $this->actingAs($user)->post('/conversions/'.$conversion->id.'/match', [
            'visit_id' => $visit->id,
        ])->assertRedirect();

        $conversion->refresh();
        $this->assertSame('uploaded', $conversion->status);
        $this->assertSame('manual', $conversion->match_source);
        $this->assertSame($visit->id, $conversion->visit_id);
    }

    public function test_bad_signature_is_rejected(): void
    {
        [, $track] = $this->makeTrack('digistore24');

        $this->post('/hooks/digistore24/'.$track->postback_secret, [
            'order_id' => 'DS-1',
            'amount' => '5',
            'currency' => 'USD',
            'sha_sign' => 'NOTVALID',
        ])->assertStatus(401);

        $this->assertSame(0, Conversion::query()->count());
    }

    public function test_tracker_records_a_session_and_flags_low_time_without_blocking(): void
    {
        [, $track] = $this->makeTrack();
        $scorer = new FraudScorer;

        foreach (range(1, 5) as $index) {
            Visit::query()->create([
                'session_id' => '11111111-1111-4111-8111-11111111111'.$index,
                'track_id' => $track->id,
                'page_type' => 'review',
                'ip_address' => '203.0.113.10',
                'landed_at' => now()->subHours($index + 1),
                'time_on_page_seconds' => 8,
                'engagement' => ['cta_clicks' => 0],
            ]);
        }

        $current = Visit::query()->create([
            'session_id' => '22222222-2222-4222-8222-222222222222',
            'track_id' => $track->id,
            'page_type' => 'review',
            'ip_address' => '203.0.113.10',
            'landed_at' => now(),
            'time_on_page_seconds' => 8,
            'engagement' => ['cta_clicks' => 0],
        ]);

        $score = $scorer->score($current);
        $this->assertTrue($score['flagged']);
        $this->assertFalse($score['block']);

        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'time_on_page' => 3,
            'gclid' => 'click-9',
            'engagement' => ['cta_clicks' => 1, 'scroll_depth' => 20],
        ])->assertOk()->assertJsonPath('page_type', 'review_robust');

        $this->assertDatabaseHas('visits', [
            'gclid' => 'click-9',
            'track_id' => $track->id,
        ]);
    }

    public function test_bot_visit_queues_an_ip_exclusion_with_a_clear_error_when_no_campaign_is_selected(): void
    {
        [, $track] = $this->makeTrack();

        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'engagement' => [],
        ], ['User-Agent' => 'Mozilla/5.0 Googlebot/2.1'])->assertOk();

        $visit = Visit::query()->where('user_agent', 'like', '%Googlebot%')->first();
        $this->assertTrue($visit->auto_blocked);
        $this->assertDatabaseHas('ip_blocks', [
            'track_id' => $track->id,
            'status' => 'failed',
        ]);
    }

    public function test_dashboard_flags_spend_against_commission(): void
    {
        [$user, $track] = $this->makeTrack();
        $track->update(['typical_commission_cad' => 100]);
        AdSpend::query()->create([
            'track_id' => $track->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 60,
        ]);

        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('report.flags.0.message', 'No conversions, and spend has reached 50 percent of the typical commission for one sale.')
        );
    }

    public function test_page_export_includes_the_track_script(): void
    {
        [$user, $track] = $this->makeTrack();
        $page = $track->pages()->create([
            'name' => 'Review',
            'page_type' => 'review',
            'language' => 'en',
            'structure_json' => ['headline' => 'Hello'],
            'html' => '<h1>Hello</h1>',
            'injected_script' => $track->scriptTag(1),
        ]);
        $page->update(['injected_script' => $track->scriptTag($page->id)]);

        $this->actingAs($user)->get('/pages/'.$page->id.'/export')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="index.html"')
            ->assertSee($track->public_script_key, false)
            ->assertSee('/t.js', false);
    }

    /**
     * @return array{0: User, 1: Track, 2: Visit}
     */
    private function makeTrack(string $platform = 'clickbank'): array
    {
        config([
            'flowhub.google_ads.client_id' => 'client',
            'flowhub.google_ads.client_secret' => 'secret',
            'flowhub.google_ads.developer_token' => 'developer',
        ]);

        $user = User::factory()->create();
        $account = GoogleAdsAccount::query()->create([
            'customer_id' => '1234567890',
            'refresh_token' => 'refresh-token',
            'name' => 'Ads',
            'status' => 'linked',
        ]);
        $action = $account->conversionActions()->create([
            'resource_name' => 'customers/1234567890/conversionActions/99',
            'external_id' => '99',
            'name' => 'Offline',
        ]);
        $track = Track::query()->create([
            'product_name' => 'Sample Offer',
            'platform' => $platform,
            'affiliate_url' => 'https://example.test/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $account->id,
            'conversion_action_id' => $action->id,
            'campaign_ids' => [],
        ]);
        $visit = Visit::query()->create([
            'session_id' => '33333333-3333-4333-8333-333333333333',
            'track_id' => $track->id,
            'page_type' => 'review_robust',
            'gclid' => 'gclid-1',
            'ip_address' => '198.51.100.8',
            'landed_at' => now(),
            'engagement' => ['cta_clicks' => 0],
        ]);

        return [$user, $track, $visit];
    }
}
