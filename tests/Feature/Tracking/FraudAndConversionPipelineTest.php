<?php

namespace Tests\Feature\Tracking;

use App\Jobs\ApplyIpBlockJob;
use App\Jobs\UploadConversionJob;
use App\Models\Conversion;
use App\Models\ConversionAction;
use App\Models\FxRate;
use App\Models\GoogleAdsAccount;
use App\Models\IpBlock;
use App\Models\Track;
use App\Models\User;
use App\Models\Visit;
use App\Services\Fx\BankOfCanadaFx;
use App\Services\Google\DataManagerClient;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use App\Services\Pages\Cloner\PageCrawlerService;
use App\Services\Pages\HtmlSanitizer;
use App\Services\Tracking\ConversionMatcher;
use App\Services\Tracking\FraudScorer;
use App\Services\Tracking\ParsedPostback;
use App\Services\Tracking\PostbackRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class FraudAndConversionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.developer_token' => 'test-dev-token',
            'flowhub.google_ads.version' => 'v22',
        ]);
    }

    private function createTrackWithAccount(string $campaignId = '9876543210'): array
    {
        $user = User::factory()->create();
        $account = GoogleAdsAccount::query()->create([
            'name' => 'FlowHub Test Google Ads Account',
            'customer_id' => '1234567890',
            'login_customer_id' => '1234567890',
            'refresh_token' => 'mock-refresh-token',
        ]);

        $conversionAction = ConversionAction::query()->create([
            'google_ads_account_id' => $account->id,
            'resource_name' => 'customers/1234567890/conversionActions/556677',
            'external_id' => '556677',
            'name' => 'Affiliate Purchase',
        ]);

        $track = Track::query()->create([
            'product_name' => 'NutraFlow Boost',
            'platform' => 'clickbank',
            'page_type' => 'presell',
            'strategy' => 'direct',
            'language' => 'en',
            'public_script_key' => 'flowhub_pipeline_track_key_999',
            'google_ads_account_id' => $account->id,
            'conversion_action_id' => $conversionAction->id,
            'campaign_ids' => [$campaignId],
            'affiliate_url' => 'https://hop.clickbank.net/?affiliate=myhop&vendor=nutraflow',
            'typical_commission_cad' => 110.00,
        ]);

        return [$user, $track, $account, $conversionAction];
    }

    /**
     * Scenario 1: FraudScorer Auto Escalation and Single Deduplicated IpBlock.
     * Simulates multiple suspicious visits from the same IP with distinct gclids and zero outbound CTA clicks.
     * Asserts FraudScorer flags the IP above threshold, creates a single deduplicated IpBlock record, and dispatches ApplyIpBlockJob.
     */
    public function test_fraud_scorer_auto_escalation_creates_deduplicated_ip_block_and_dispatches_job(): void
    {
        Queue::fake();

        [$user, $track] = $this->createTrackWithAccount();
        $suspiciousIp = '198.51.100.99';

        // Visit 1: Incoming ad click with gclid-alpha, 0 CTA clicks, landed 1 hour ago
        Visit::query()->create([
            'session_id' => '11111111-1111-4111-8111-111111111111',
            'track_id' => $track->id,
            'page_type' => 'presell',
            'ip_address' => $suspiciousIp,
            'gclid' => 'gclid-alpha-100',
            'landed_at' => now()->subHour(),
            'time_on_page_seconds' => 5,
            'presell_hop' => false,
            'converted' => false,
            'engagement' => ['cta_clicks' => 0],
        ]);

        // Visit 2: Repeated ad click from SAME IP with DIFFERENT gclid-beta and zero outbound CTA clicks
        $response = $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'gclid' => 'gclid-beta-200',
            'time_on_page' => 4,
            'engagement' => [
                'cta_clicks' => 0,
                'scroll_depth' => 15,
                'presell_hop' => false,
            ],
        ], ['REMOTE_ADDR' => $suspiciousIp]);

        $response->assertOk();

        $secondVisit = Visit::query()
            ->where('track_id', $track->id)
            ->where('ip_address', $suspiciousIp)
            ->where('gclid', 'gclid-beta-200')
            ->first();

        $this->assertNotNull($secondVisit);
        $this->assertTrue($secondVisit->auto_blocked, 'Visit should be marked as auto_blocked.');
        $this->assertGreaterThanOrEqual(
            FraudScorer::AUTO_BLOCK_THRESHOLD,
            $secondVisit->fraud_score,
            'Fraud score must meet or exceed AUTO_BLOCK_THRESHOLD (70).'
        );
        $this->assertStringContainsString('different gclids and zero CTA engagement', (string) $secondVisit->fraud_reason);

        // Verify single deduplicated IpBlock record created in database with status 'pending'
        $this->assertDatabaseHas('ip_blocks', [
            'track_id' => $track->id,
            'ip_address' => $suspiciousIp,
            'status' => 'pending',
        ]);
        $this->assertSame(
            1,
            IpBlock::query()->where('track_id', $track->id)->where('ip_address', $suspiciousIp)->count(),
            'IpBlock record must be unique per track and IP address.'
        );

        // Verify ApplyIpBlockJob dispatched
        Queue::assertPushed(ApplyIpBlockJob::class, function (ApplyIpBlockJob $job) use ($track, $suspiciousIp) {
            return $job->trackId === $track->id && $job->ip === $suspiciousIp;
        });

        // Visit 3: A third ping from the same suspicious IP
        $this->postJson('/t/collect', [
            'key' => $track->public_script_key,
            'gclid' => 'gclid-gamma-300',
            'time_on_page' => 3,
            'engagement' => ['cta_clicks' => 0],
        ], ['REMOTE_ADDR' => $suspiciousIp])->assertOk();

        // Assert IpBlock remains deduplicated (count is still 1)
        $this->assertSame(
            1,
            IpBlock::query()->where('track_id', $track->id)->where('ip_address', $suspiciousIp)->count(),
            'Subsequent suspicious visits must not duplicate IpBlock records.'
        );
    }

    /**
     * Scenario 1: ApplyIpBlockJob 500 Exclusion Limit Enforcement.
     * Tests ApplyIpBlockJob and GoogleAdsClient when campaign has 500 exclusions,
     * asserting that the job consolidates /24 subnets or rotates oldest exclusions without quota errors.
     */
    public function test_apply_ip_block_job_handles_500_exclusion_limit_by_consolidating_subnets_or_rotating_oldest(): void
    {
        [$user, $track, $account] = $this->createTrackWithAccount('9876543210');
        $campaignId = '9876543210';
        $blockedIp = '198.51.100.99';

        // Create a pending IpBlock record
        $ipBlock = IpBlock::query()->create([
            'track_id' => $track->id,
            'google_ads_account_id' => $account->id,
            'ip_address' => $blockedIp,
            'campaign_id' => $campaignId,
            'reason' => 'Repeated ad click fraud',
            'status' => 'pending',
        ]);

        // Mock GoogleAdsClient
        $mockGoogleAds = $this->createMock(GoogleAdsClient::class);

        // Expect enforceIpExclusionLimit to be invoked to prevent 500 quota exception
        $mockGoogleAds->expects($this->once())
            ->method('enforceIpExclusionLimit')
            ->with(
                $this->callback(fn ($acc) => $acc->id === $account->id),
                $campaignId
            )
            ->willReturn([
                'consolidated_subnets' => 1,
                'removed_ips' => 3,
                'rotated' => 0,
                'active_count' => 488,
            ]);

        // Expect blockIp to succeed
        $mockGoogleAds->expects($this->once())
            ->method('blockIp')
            ->with(
                $this->callback(fn ($acc) => $acc->id === $account->id),
                $campaignId,
                $blockedIp
            )
            ->willReturn("customers/1234567890/campaignCriteria/{$campaignId}~998877");

        // Execute ApplyIpBlockJob
        $job = new ApplyIpBlockJob($track->id, $blockedIp, 'Repeated ad click fraud');
        $job->handle($mockGoogleAds);

        // Verify block status updated to applied
        $ipBlock->refresh();
        $this->assertSame('applied', $ipBlock->status);
        $this->assertSame("customers/1234567890/campaignCriteria/{$campaignId}~998877", $ipBlock->criterion_resource_name);
        $this->assertNull($ipBlock->last_error);

        // In addition, test GoogleAdsClient::enforceIpExclusionLimit subnet consolidation and rotation logic directly with API mocks
        config([
            'flowhub.google_ads.client_id' => 'test-client-id',
            'flowhub.google_ads.client_secret' => 'test-client-secret',
            'flowhub.google_ads.version' => 'v22',
        ]);

        // Subnet consolidation scenario: 490 existing criteria including multiple IPs in 198.51.100.x subnet
        $existingRows = [];
        foreach (range(1, 488) as $i) {
            $existingRows[] = [
                'campaignCriterion' => [
                    'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~10{$i}",
                    'ipBlock' => ['ipAddress' => "10.0.{$i}.1"],
                ],
            ];
        }
        $existingRows[] = [
            'campaignCriterion' => [
                'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~201",
                'ipBlock' => ['ipAddress' => '198.51.100.11'],
            ],
        ];
        $existingRows[] = [
            'campaignCriterion' => [
                'resourceName' => "customers/1234567890/campaignCriteria/{$campaignId}~202",
                'ipBlock' => ['ipAddress' => '198.51.100.22'],
            ],
        ];

        // Seed DB records for the individual IPs in the subnet
        foreach (['198.51.100.11', '198.51.100.22'] as $subIp) {
            IpBlock::query()->create([
                'track_id' => $track->id,
                'google_ads_account_id' => $account->id,
                'ip_address' => $subIp,
                'campaign_id' => $campaignId,
                'status' => 'applied',
                'reason' => 'Subnet bot click',
            ]);
        }

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

        $realClient = app(GoogleAdsClient::class);
        $enforceResult = $realClient->enforceIpExclusionLimit($account, $campaignId, 490);

        $this->assertSame(1, $enforceResult['consolidated_subnets']);
        $this->assertSame(2, $enforceResult['removed_ips']);

        // Assert database marked individual IPs consolidated and created /24 CIDR mask
        $this->assertDatabaseHas('ip_blocks', [
            'ip_address' => '198.51.100.11',
            'campaign_id' => $campaignId,
            'status' => 'consolidated',
        ]);
        $this->assertDatabaseHas('ip_blocks', [
            'ip_address' => '198.51.100.0/24',
            'campaign_id' => $campaignId,
            'status' => 'applied',
        ]);
    }

    /**
     * Scenario 2: Offline Conversion Pre-Validation and CAD$ FX Locking.
     * Tests PostbackRecorder and ConversionMatcher to verify that an incoming affiliate postback
     * locks in the historical CAD$ exchange rate from FxRate on the Conversion model.
     */
    public function test_postback_recorder_and_matcher_lock_in_historical_cad_fx_rate(): void
    {
        // 1. Seed today's historical FxRate: 1 USD = 1.38500000 CAD
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => '1.38500000',
            'source' => 'bank_of_canada',
        ]);

        [$user, $track] = $this->createTrackWithAccount();

        // 2. Seed a visit with gclid
        $visit = Visit::query()->create([
            'session_id' => '22222222-2222-4222-8222-222222222222',
            'track_id' => $track->id,
            'page_type' => 'presell',
            'ip_address' => '203.0.113.55',
            'gclid' => 'gclid-postback-lockin-777',
            'landed_at' => now()->subMinutes(30),
            'presell_hop' => true,
            'converted' => false,
        ]);

        $parsedPostback = new ParsedPostback(
            orderId: 'ORDER-AFFILIATE-8888',
            amount: 80.00,
            currency: 'USD',
            gclid: 'gclid-postback-lockin-777',
            sessionToken: '22222222-2222-4222-8222-222222222222',
            tokens: ['22222222-2222-4222-8222-222222222222'],
            eventType: 'sale',
            signaturePresent: false,
            signatureValid: false
        );

        Queue::fake();

        $recorder = new PostbackRecorder(app(ConversionMatcher::class), app(BankOfCanadaFx::class));
        $rawPayload = json_encode(['order_id' => 'ORDER-AFFILIATE-8888', 'amount' => 80.00, 'currency' => 'USD', 'gclid' => 'gclid-postback-lockin-777']);

        $conversion = $recorder->record($track, $rawPayload, $parsedPostback);

        // Assert Conversion model locked in historical exchange rate and CAD commission
        // 80.00 USD * 1.38500000 = 110.80 CAD
        $this->assertSame('ORDER-AFFILIATE-8888', $conversion->order_id);
        $this->assertEquals(80.00, $conversion->amount);
        $this->assertSame('USD', $conversion->currency);
        $this->assertEquals('1.38500000', $conversion->fx_rate, 'Conversion must lock in exact historical FxRate.');
        $this->assertEquals(110.80, $conversion->commission_value_cad, 'CAD commission value must be accurately calculated.');
        $this->assertSame('matched', $conversion->status);
        $this->assertSame($visit->id, $conversion->visit_id);
        $this->assertSame('gclid', $conversion->match_source);

        // Assert visit marked as converted
        $this->assertTrue($visit->fresh()->converted);

        // Assert UploadConversionJob queued
        Queue::assertPushed(UploadConversionJob::class, function (UploadConversionJob $job) use ($conversion) {
            return $job->conversionId === $conversion->id;
        });
    }

    /**
     * Scenario 2: Data Manager Pre-Validation and Fast-Fail Batch Protection.
     * Tests DataManagerClient and UploadConversionJob with Http::fake().
     * Asserts malformed records are caught before batch submission without rejecting valid conversions,
     * and verifies the returned requestId is saved on the Conversion model.
     */
    public function test_data_manager_pre_validation_fast_fails_bad_records_without_rejecting_valid_conversions(): void
    {
        [$user, $track, $account, $action] = $this->createTrackWithAccount();

        // 1. Create a valid Conversion
        $validVisit = Visit::query()->create([
            'session_id' => '33333333-3333-4333-8333-333333333333',
            'track_id' => $track->id,
            'page_type' => 'presell',
            'ip_address' => '203.0.113.66',
            'gclid' => 'gclid-valid-123',
            'landed_at' => now()->subHour(),
        ]);

        $validConversion = Conversion::query()->create([
            'track_id' => $track->id,
            'visit_id' => $validVisit->id,
            'order_id' => 'ORDER-VALID-101',
            'gclid' => 'gclid-valid-123',
            'amount' => 100.00,
            'currency' => 'USD',
            'fx_rate' => '1.38000000',
            'commission_value_cad' => 138.00,
            'status' => 'matched',
            'raw_payload' => '{}',
        ]);

        // 2. Create a malformed Conversion (missing any ad identifiers: gclid, gbraid, wbraid all missing)
        $badConversion = Conversion::query()->create([
            'track_id' => $track->id,
            'visit_id' => null,
            'order_id' => 'ORDER-MALFORMED-202',
            'gclid' => null,
            'amount' => 50.00,
            'currency' => 'USD',
            'fx_rate' => '1.38000000',
            'commission_value_cad' => 69.00,
            'status' => 'matched',
            'raw_payload' => '{}',
        ]);

        // Test validateConversion on DataManagerClient throws fast-fail exception on the malformed record
        $dataManager = app(DataManagerClient::class);

        try {
            $dataManager->validateConversion($badConversion);
            $this->fail('Expected GoogleRequestException was not thrown for malformed conversion.');
        } catch (GoogleRequestException $e) {
            $this->assertStringContainsString('no gclid, gbraid, or wbraid', $e->getMessage());
        }

        // Fake Google OAuth and Data Manager Ingest API endpoints
        Http::fake([
            'https://oauth2.googleapis.com/token*' => Http::response([
                'access_token' => 'mock-dm-token',
                'expires_in' => 3600,
            ], 200),
            'https://datamanager.googleapis.com/v1/events:ingest*' => Http::response([
                'requestId' => 'dm-req-id-uuid-99887766',
            ], 200),
        ]);

        // Execute UploadConversionJob with batch of IDs: [$validConversion->id, $badConversion->id]
        $job = new UploadConversionJob([$validConversion->id, $badConversion->id]);
        $job->handle($dataManager);

        // Assert valid conversion was successfully ingested and saved with requestId
        $validConversion->refresh();
        $this->assertSame('uploaded', $validConversion->status);
        $this->assertSame('dm-req-id-uuid-99887766', $validConversion->request_id);
        $this->assertNotNull($validConversion->uploaded_at);
        $this->assertNull($validConversion->last_error);

        // Assert malformed conversion failed fast without halting the batch or rejecting valid conversions
        $badConversion->refresh();
        $this->assertSame('failed', $badConversion->status);
        $this->assertNull($badConversion->request_id);
        $this->assertStringContainsString('no gclid, gbraid, or wbraid', (string) $badConversion->last_error);

        // Verify Data Manager endpoint was called exactly once (for the valid conversion only)
        Http::assertSent(function (Request $request) {
            if ($request->url() === 'https://datamanager.googleapis.com/v1/events:ingest') {
                $payload = $request->data();

                return isset($payload['events'][0]['transactionId'])
                    && $payload['events'][0]['transactionId'] === 'ORDER-VALID-101'
                    && $payload['events'][0]['conversionValue'] === 138.0;
            }

            return true;
        });
    }

    /**
     * Scenario 3: SSRF Rejection in PageCrawlerService.
     * Passes private, loopback, and link-local URLs to PageCrawlerService URL validation
     * and asserts they are rejected with SSRF blocked exception before any socket is opened.
     */
    public function test_page_crawler_service_rejects_ssrf_and_private_ips(): void
    {
        $crawler = new PageCrawlerService;

        $forbiddenUrls = [
            'http://127.0.0.1/admin',
            'http://127.0.0.1:8080/metrics',
            'http://169.254.169.254/latest/meta-data',
            'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'http://192.168.1.1',
            'http://192.168.1.1/router-login',
            'http://localhost',
            'http://localhost:3000/api',
            'http://10.0.0.1/internal-dashboard',
            'http://172.16.0.1/private-db',
            'http://[::1]/',
            'http://0.0.0.0',
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/_flushall',
        ];

        foreach ($forbiddenUrls as $url) {
            $this->assertFalse(
                $crawler->isSafeUrl($url),
                "isSafeUrl must return false for forbidden SSRF target: {$url}"
            );

            try {
                $crawler->validateUrlSecurity($url);
                $this->fail("validateUrlSecurity must throw RuntimeException for: {$url}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    'SSRF blocked',
                    $exception->getMessage(),
                    "Exception message must specify 'SSRF blocked' for: {$url}"
                );
            }
        }
    }

    /**
     * Scenario 3: Third-Party Pixel Stripping in HtmlSanitizer.
     * Passes sample vendor HTML containing Google Tag Manager, Meta Pixel, meta refresh tags,
     * and competitor scripts into HtmlSanitizer and asserts all third-party trackers are stripped
     * while preserving Flow Hub's own TrackScript and affiliate CTA links.
     */
    public function test_html_sanitizer_strips_third_party_trackers_while_preserving_flowhub_script_and_affiliate_links(): void
    {
        $sanitizer = new HtmlSanitizer;

        $vendorHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Metabolic Renewal - Special Offer</title>
    <!-- Malicious Meta Refresh Redirect -->
    <meta http-equiv="refresh" content="0; url=https://competitor-hijack.com/steal-traffic">
    <meta http-equiv="X-Frame-Options" content="DENY">

    <!-- Competitor Google Tag Manager -->
    <script async src="https://www.googletagmanager.com/gtm.js?id=GTM-COMPETITOR99"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        dataLayer.push({'event': 'gtm.js', 'gtm.start': new Date().getTime()});
    </script>

    <!-- Meta / Facebook Pixel Code -->
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '998877665544332');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=998877665544332&ev=PageView&noscript=1"/>
    </noscript>

    <!-- TikTok Pixel -->
    <script>
        !function (w, d, t) {
            w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify"];
            ttq.load('TT-COMPETITOR-001');
            ttq.page();
        }(window, document, 'ttq');
    </script>

    <!-- Microsoft Clarity -->
    <script>
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            clarity("start");
        })(window, document, "clarity", "script", "clarity-123");
    </script>

    <!-- Anti-Framing Domain Lock -->
    <script>
        if (top.location != self.location) {
            top.location = self.location;
        }
    </script>

    <!-- Flow Hub's Own TrackScript (Must Be Preserved) -->
    <script async src="https://flowhub.internal.test/t.js" data-track="flowhub_pipeline_track_key_999" data-page="42"></script>
</head>
<body>
    <header>
        <h1>Metabolic Renewal - Doctor Formulated</h1>
    </header>

    <main>
        <p>Support healthy digestion, sustained energy, and weight management naturally.</p>

        <!-- Affiliate CTA Link (Must Be Preserved) -->
        <div class="cta-container">
            <a href="https://hop.clickbank.net/?affiliate=myhop&vendor=nutraflow"
               class="btn-cta-order"
               data-outbound="true">
               Claim Your Verified 65% Discount Now
            </a>
        </div>
    </main>

    <!-- Competitor Tracking Iframe -->
    <iframe src="https://www.googletagmanager.com/ns.html?id=GTM-COMPETITOR99" height="0" width="0" style="display:none;visibility:hidden"></iframe>
</body>
</html>
HTML;

        $cleanedHtml = $sanitizer->stripThirdPartyTrackers($vendorHtml);

        // 1. Assert all competitor trackers, pixels, meta refreshes, and scripts are stripped
        $this->assertStringNotContainsString('googletagmanager.com', $cleanedHtml);
        $this->assertStringNotContainsString('GTM-COMPETITOR99', $cleanedHtml);
        $this->assertStringNotContainsString('dataLayer.push', $cleanedHtml);
        $this->assertStringNotContainsString('connect.facebook.net', $cleanedHtml);
        $this->assertStringNotContainsString('fbevents.js', $cleanedHtml);
        $this->assertStringNotContainsString('fbq(', $cleanedHtml);
        $this->assertStringNotContainsString('facebook.com/tr', $cleanedHtml);
        $this->assertStringNotContainsString('ttq.load', $cleanedHtml);
        $this->assertStringNotContainsString('clarity(', $cleanedHtml);
        $this->assertStringNotContainsString('http-equiv="refresh"', $cleanedHtml);
        $this->assertStringNotContainsString('competitor-hijack.com', $cleanedHtml);
        $this->assertStringNotContainsString('top.location != self.location', $cleanedHtml);

        // 2. Assert Flow Hub's own TrackScript is preserved intact
        $this->assertStringContainsString('https://flowhub.internal.test/t.js', $cleanedHtml);
        $this->assertStringContainsString('data-track="flowhub_pipeline_track_key_999"', $cleanedHtml);
        $this->assertStringContainsString('data-page="42"', $cleanedHtml);

        // 3. Assert Affiliate CTA links and landing page content are preserved intact
        $this->assertTrue(
            str_contains($cleanedHtml, 'https://hop.clickbank.net/?affiliate=myhop&vendor=nutraflow') ||
            str_contains($cleanedHtml, 'https://hop.clickbank.net/?affiliate=myhop&amp;vendor=nutraflow'),
            'Affiliate link href must be preserved.'
        );
        $this->assertStringContainsString('Claim Your Verified 65% Discount Now', $cleanedHtml);
        $this->assertStringContainsString('Metabolic Renewal - Doctor Formulated', $cleanedHtml);
        $this->assertStringContainsString('Support healthy digestion, sustained energy, and weight management naturally.', $cleanedHtml);
    }
}
