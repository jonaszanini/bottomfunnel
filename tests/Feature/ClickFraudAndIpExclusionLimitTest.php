<?php

namespace Tests\Feature;

use App\Jobs\ApplyIpBlockJob;
use App\Models\GoogleAdsAccount;
use App\Models\IpBlock;
use App\Models\Track;
use App\Models\User;
use App\Models\Visit;
use App\Services\Google\GoogleAdsClient;
use App\Services\Tracking\FraudScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClickFraudAndIpExclusionLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndTrack(): array
    {
        $user = User::factory()->create();
        $account = GoogleAdsAccount::query()->create([
            'name' => 'Test Google Ads Account',
            'customer_id' => '1234567890',
            'refresh_token' => 'dummy-refresh-token',
        ]);

        $track = Track::query()->create([
            'product_name' => 'FlowHub Test Offer',
            'platform' => 'clickbank',
            'page_type' => 'presell',
            'strategy' => 'direct',
            'language' => 'en',
            'public_script_key' => 'flowhub_test_track_key_123',
            'google_ads_account_id' => $account->id,
            'campaign_ids' => ['9876543210'],
            'affiliate_url' => 'https://hop.clickbank.net/?affiliate=test&vendor=flowhub',
        ]);

        return [$user, $track, $account];
    }

    public function test_tracker_records_presell_hop_and_calculates_ctr(): void
    {
        [$user, $track] = $this->makeUserAndTrack();

        // 1. First ping (landed on page, no hop yet)
        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'time_on_page' => 2,
            'gclid' => 'click-test-1',
            'engagement' => ['scroll_depth' => 25],
        ])->assertOk();

        $visit = Visit::query()->where('track_id', $track->id)->first();
        $this->assertNotNull($visit);
        $this->assertFalse($visit->presell_hop);
        $this->assertNull($visit->hopped_at);

        // 2. Second ping with outbound affiliate CTA click (presell hop)
        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'session_id' => $visit->session_id,
            'time_on_page' => 15,
            'presell_hop' => true,
            'engagement' => [
                'scroll_depth' => 80,
                'cta_clicks' => 1,
                'presell_hop' => true,
            ],
        ])->assertOk();

        $visit->refresh();
        $this->assertTrue($visit->presell_hop);
        $this->assertTrue($visit->isHopped());
        $this->assertNotNull($visit->hopped_at);

        // 3. Verify CTR in Track detail response
        $this->actingAs($user)->get("/tracks/{$track->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tracks/Show')
                ->where('track.total_visits', 1)
                ->where('track.hop_visits', 1)
                ->where('track.presell_hop_ctr', 100)
                ->where('visits.0.presell_hop', true)
            );
    }

    public function test_repeated_ad_clicks_with_different_gclids_and_zero_cta_escalates_to_auto_block(): void
    {
        Queue::fake();

        [$user, $track] = $this->makeUserAndTrack();
        $testIp = '198.51.100.44';

        // Visit 1: ad click with gclid-alpha, 0 CTA clicks, no hop
        Visit::query()->create([
            'session_id' => '11111111-1111-4111-8111-111111111111',
            'track_id' => $track->id,
            'page_type' => 'presell',
            'ip_address' => $testIp,
            'gclid' => 'gclid-alpha-123',
            'landed_at' => now()->subHours(2),
            'time_on_page_seconds' => 10,
            'presell_hop' => false,
            'converted' => false,
            'engagement' => ['cta_clicks' => 0],
        ]);

        // Visit 2: incoming from same IP with different gclid-beta and zero CTA engagement
        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'gclid' => 'gclid-beta-456',
            'time_on_page' => 4,
            'engagement' => ['cta_clicks' => 0, 'scroll_depth' => 10],
        ], ['REMOTE_ADDR' => $testIp])->assertOk();

        $visit = Visit::query()->where('ip_address', $testIp)->where('gclid', 'gclid-beta-456')->first();
        $this->assertNotNull($visit);
        $this->assertTrue($visit->auto_blocked);
        $this->assertGreaterThanOrEqual(FraudScorer::AUTO_BLOCK_THRESHOLD, $visit->fraud_score);
        $this->assertStringContainsString('different gclids and zero CTA engagement', (string) $visit->fraud_reason);

        // Verify IpBlock created with status 'pending'
        $this->assertDatabaseHas('ip_blocks', [
            'track_id' => $track->id,
            'ip_address' => $testIp,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ApplyIpBlockJob::class, function (ApplyIpBlockJob $job) use ($track, $testIp) {
            return $job->trackId === $track->id && $job->ip === $testIp;
        });
    }

    public function test_repeated_ad_clicks_with_same_gclids_or_active_cta_engagement_is_not_blocked(): void
    {
        [$user, $track] = $this->makeUserAndTrack();
        $testIp = '198.51.100.88';

        // Visit 1: hopped to offer
        Visit::query()->create([
            'session_id' => '33333333-3333-4333-8333-333333333333',
            'track_id' => $track->id,
            'page_type' => 'presell',
            'ip_address' => $testIp,
            'gclid' => 'gclid-first',
            'landed_at' => now()->subHours(1),
            'time_on_page_seconds' => 40,
            'presell_hop' => true,
            'converted' => false,
            'engagement' => ['cta_clicks' => 1],
        ]);

        $scorer = new FraudScorer;
        $currentVisit = new Visit([
            'track_id' => $track->id,
            'ip_address' => $testIp,
            'gclid' => 'gclid-second',
            'landed_at' => now(),
            'time_on_page_seconds' => 30,
            'presell_hop' => false,
            'converted' => false,
            'engagement' => ['cta_clicks' => 0],
        ]);

        $score = $scorer->score($currentVisit);
        // Because IP hopped previously, it is not flagged as zero CTA click fraud
        $this->assertFalse($score['block']);
    }

    public function test_one_click_block_action_queues_apply_ip_block_job(): void
    {
        Queue::fake();

        [$user, $track] = $this->makeUserAndTrack();
        $targetIp = '203.0.113.123';

        $this->actingAs($user)->post('/ip-blocks/block', [
            'track_id' => $track->id,
            'ip_address' => $targetIp,
            'reason' => 'One click block from telemetry inspection',
        ])->assertRedirect();

        $this->assertDatabaseHas('ip_blocks', [
            'track_id' => $track->id,
            'ip_address' => $targetIp,
            'status' => 'pending',
            'reason' => 'One click block from telemetry inspection',
        ]);

        Queue::assertPushed(ApplyIpBlockJob::class, function (ApplyIpBlockJob $job) use ($track, $targetIp) {
            return $job->trackId === $track->id && $job->ip === $targetIp;
        });
    }

    public function test_whitelisted_ip_is_skipped_by_one_click_block_and_scorer(): void
    {
        Queue::fake();

        [$user, $track] = $this->makeUserAndTrack();
        $whitelistedIp = '203.0.113.77';

        IpBlock::query()->create([
            'track_id' => $track->id,
            'ip_address' => $whitelistedIp,
            'campaign_id' => '9876543210',
            'reason' => 'Internal office IP',
            'status' => 'whitelisted',
        ]);

        // Attempt one-click block on whitelisted IP
        $this->actingAs($user)->post('/ip-blocks/block', [
            'track_id' => $track->id,
            'ip_address' => $whitelistedIp,
        ])->assertSessionHas('error');

        Queue::assertNotPushed(ApplyIpBlockJob::class);

        // Scorer skips whitelisted IP
        $scorer = new FraudScorer;
        $botVisit = new Visit([
            'track_id' => $track->id,
            'ip_address' => $whitelistedIp,
            'user_agent' => 'Googlebot/2.1',
        ]);
        $score = $scorer->score($botVisit);
        $this->assertFalse($score['block']);
    }

    public function test_google_ads_enforce_ip_exclusion_limit_consolidates_24_subnets(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
        ]);

        [$user, $track, $account] = $this->makeUserAndTrack();
        $campaignId = '9876543210';

        // Seed 490 existing blocks, including 3 in 198.51.100.x subnet
        $existingRows = [];
        foreach (range(1, 488) as $i) {
            $existingRows[] = [
                'campaignCriterion' => [
                    'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~100{$i}",
                    'ipBlock' => ['ipAddress' => "10.0.{$i}.1"],
                ],
            ];
        }

        // Subnet 198.51.100.x IPs
        $existingRows[] = [
            'campaignCriterion' => [
                'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~2001",
                'ipBlock' => ['ipAddress' => '198.51.100.10'],
            ],
        ];
        $existingRows[] = [
            'campaignCriterion' => [
                'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~2002",
                'ipBlock' => ['ipAddress' => '198.51.100.20'],
            ],
        ];
        $existingRows[] = [
            'campaignCriterion' => [
                'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~2003",
                'ipBlock' => ['ipAddress' => '198.51.100.30'],
            ],
        ];

        // Seed DB records for the subnet IPs
        foreach (['198.51.100.10', '198.51.100.20', '198.51.100.30'] as $subIp) {
            IpBlock::query()->create([
                'track_id' => $track->id,
                'google_ads_account_id' => $account->id,
                'ip_address' => $subIp,
                'campaign_id' => $campaignId,
                'status' => 'applied',
                'reason' => 'Individual bot click',
            ]);
        }

        // Mock Google Ads API:
        // 1. search for criteria returns 491 items
        // 2. mutate creates 198.51.100.0/24
        // 3. mutate removes 3 individual criteria
        // 4. search returns consolidated count
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock-token', 'expires_in' => 3600], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::sequence()
                ->push(['results' => $existingRows], 200)
                ->push(['results' => array_slice($existingRows, 0, 489)], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response([
                'results' => [
                    ['resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~9999"],
                ],
            ], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->enforceIpExclusionLimit($account, $campaignId, 490);

        $this->assertEquals(1, $result['consolidated_subnets']);
        $this->assertEquals(3, $result['removed_ips']);

        // Check that database records for the individual IPs are marked consolidated
        $this->assertDatabaseHas('ip_blocks', [
            'ip_address' => '198.51.100.10',
            'campaign_id' => $campaignId,
            'status' => 'consolidated',
        ]);

        // Check that the new CIDR mask was created in DB
        $this->assertDatabaseHas('ip_blocks', [
            'ip_address' => '198.51.100.0/24',
            'campaign_id' => $campaignId,
            'status' => 'applied',
        ]);
    }

    public function test_google_ads_enforce_ip_exclusion_limit_rotates_oldest_when_no_subnets_available(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
        ]);

        [$user, $track, $account] = $this->makeUserAndTrack();
        $campaignId = '9876543210';

        // 496 unique IPs in completely different subnets
        $existingRows = [];
        foreach (range(1, 496) as $i) {
            $existingRows[] = [
                'campaignCriterion' => [
                    'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~{$i}",
                    'ipBlock' => ['ipAddress' => "10.{$i}.1.1"],
                ],
            ];
        }

        // Seed some oldest DB records
        $oldest = IpBlock::query()->create([
            'track_id' => $track->id,
            'google_ads_account_id' => $account->id,
            'ip_address' => '10.1.1.1',
            'campaign_id' => $campaignId,
            'criterion_resource_name' => "customers/1234567890/campaignCriteria/{$campaignId}~1",
            'status' => 'applied',
            'reason' => 'Oldest block',
            'created_at' => now()->subDays(60),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'mock-token', 'expires_in' => 3600], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/googleAds:search' => Http::sequence()
                ->push(['results' => $existingRows], 200)
                ->push(['results' => $existingRows], 200)
                ->push(['results' => array_slice($existingRows, 11)], 200),
            'https://googleads.googleapis.com/v22/customers/1234567890/campaignCriteria:mutate' => Http::response(['results' => []], 200),
        ]);

        $client = app(GoogleAdsClient::class);
        $result = $client->enforceIpExclusionLimit($account, $campaignId, 490);

        $this->assertGreaterThan(0, $result['rotated']);

        $oldest->refresh();
        $this->assertEquals('rotated', $oldest->status);
    }
}
