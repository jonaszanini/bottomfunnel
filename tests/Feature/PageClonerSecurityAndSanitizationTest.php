<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pages\Cloner\PageClonerService;
use App\Services\Pages\Cloner\PageCrawlerService;
use App\Services\Pages\Compiler\PresellCompilerEngine;
use App\Services\Pages\HtmlDocument;
use App\Services\Pages\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PageClonerSecurityAndSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'securitytester',
        ]);
    }

    /**
     * Requirement: PageCrawlerService rejects invalid schemes and private/reserved IPv4 & IPv6 ranges.
     */
    public function test_crawler_rejects_ssrf_schemes_and_private_ips(): void
    {
        $crawler = new PageCrawlerService;

        $disallowedUrls = [
            'file:///etc/passwd',
            'ftp://example.com/file',
            'gopher://127.0.0.1:6379/_',
            'data:text/html,hello',
            'http://localhost',
            'http://localhost:8080/admin',
            'http://127.0.0.1',
            'http://127.0.0.1:3306',
            'http://127.0.1.1',
            'http://169.254.169.254/latest/meta-data',
            'http://10.0.0.1/internal',
            'http://192.168.1.1/gateway',
            'http://172.16.0.1/private',
            'http://[::1]/',
            'http://0.0.0.0',
        ];

        foreach ($disallowedUrls as $badUrl) {
            $this->assertFalse($crawler->isSafeUrl($badUrl), "Expected isSafeUrl to return false for: {$badUrl}");

            try {
                $crawler->validateUrlSecurity($badUrl);
                $this->fail("Expected validateUrlSecurity to throw RuntimeException for: {$badUrl}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('SSRF blocked', $e->getMessage());
            }
        }
    }

    /**
     * Requirement: PageCrawlerService detects SSRF redirect hops and aborts.
     */
    public function test_crawler_detects_ssrf_via_redirect_and_aborts(): void
    {
        Http::fake([
            'https://external-entry.com' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data',
            ]),
        ]);

        $crawler = new PageCrawlerService;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSRF blocked');

        $crawler->crawl('https://external-entry.com');
    }

    /**
     * Requirement: PageClonerService blocks SSRF sub-asset fetches.
     */
    public function test_cloner_blocks_ssrf_sub_asset_downloads(): void
    {
        $crawler = new PageCrawlerService;
        $sanitizer = new HtmlSanitizer;
        $cloner = new PageClonerService($crawler, $sanitizer);

        $badAssetUrls = [
            'http://127.0.0.1/style.css',
            'http://localhost:8000/image.png',
            'http://169.254.169.254/secret.jpg',
            'file:///etc/hosts',
        ];

        foreach ($badAssetUrls as $url) {
            $storedPath = $cloner->downloadAndStoreAsset($url);
            $this->assertNull($storedPath, "Expected downloadAndStoreAsset to reject: {$url}");
        }
    }

    /**
     * Requirement: Cap individual asset download sizes and sanitize local paths with deterministic content hashes.
     */
    public function test_cloner_caps_asset_download_size_and_uses_deterministic_content_hashes(): void
    {
        $crawler = new PageCrawlerService;
        $sanitizer = new HtmlSanitizer;
        $cloner = new PageClonerService($crawler, $sanitizer);

        // 1. Oversized asset (greater than 10MB)
        Http::fake([
            'https://safe-assets.test/oversized.jpg' => Http::response('OVERSIZED_ASSET_PAYLOAD', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) (11 * 1024 * 1024),
            ]),
            'https://safe-assets.test/logo.png' => Http::response('FAKE_IMAGE_BYTES_123', 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => '20',
            ]),
        ]);

        $oversizedResult = $cloner->downloadAndStoreAsset('https://safe-assets.test/oversized.jpg');
        $this->assertNull($oversizedResult, 'Asset exceeding size cap must be rejected.');

        // 2. Safe asset with deterministic content hash
        $safeResult = $cloner->downloadAndStoreAsset('https://safe-assets.test/logo.png');
        $this->assertNotNull($safeResult);

        $expectedHash = hash('sha256', 'FAKE_IMAGE_BYTES_123');
        $this->assertSame("/storage/cloned_assets/{$expectedHash}.png", $safeResult);

        $storedFile = storage_path("app/public/cloned_assets/{$expectedHash}.png");
        $this->assertFileExists($storedFile);
        $this->assertSame('FAKE_IMAGE_BYTES_123', file_get_contents($storedFile));

        // Clean up test file
        @unlink($storedFile);
    }

    /**
     * Requirement: HtmlSanitizer strips competitor tracking scripts, pixels, and meta-refresh redirects.
     */
    public function test_html_sanitizer_strips_competitor_pixels_and_meta_refresh(): void
    {
        $sanitizer = new HtmlSanitizer;

        $dirtyHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<title>Product Offer</title>
<meta http-equiv="refresh" content="0; url=https://competitor-redirect.com">
<meta http-equiv="X-Frame-Options" content="DENY">
<!-- Google Tag Manager -->
<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-XXXX"></script>
<!-- Facebook Pixel Code -->
<script>
!function(f,b,e,v,n,t,s){fbq('init', '123456789'); fbq('track', 'PageView');}();
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=123456789&ev=PageView" /></noscript>
<!-- TikTok Pixel -->
<script>
ttq.load('TTEST123');
ttq.page();
</script>
<!-- Anti Framing Lock -->
<script>
if (top.location != self.location) {
    top.location = self.location;
}
</script>
</head>
<body>
<h1>Legitimate Offer Heading</h1>
<p>Authentic product details here.</p>
<img src="https://safe-cdn.test/product.jpg" alt="Product">
</body>
</html>
HTML;

        $cleaned = $sanitizer->stripThirdPartyTrackers($dirtyHtml);

        $this->assertStringNotContainsString('googletagmanager.com', $cleaned);
        $this->assertStringNotContainsString('fbq(', $cleaned);
        $this->assertStringNotContainsString('facebook.com/tr', $cleaned);
        $this->assertStringNotContainsString('ttq.load', $cleaned);
        $this->assertStringNotContainsString('http-equiv="refresh"', $cleaned);
        $this->assertStringNotContainsString('top.location != self.location', $cleaned);

        $this->assertStringContainsString('Legitimate Offer Heading', $cleaned);
        $this->assertStringContainsString('Authentic product details here.', $cleaned);
        $this->assertStringContainsString('https://safe-cdn.test/product.jpg', $cleaned);
    }

    /**
     * Requirement: Sanitizer runs BEFORE PresellCompilerEngine injects TrackScript and affiliate hop links.
     */
    public function test_presell_compiler_sanitizes_before_injecting_flowhub_trackscript_and_affiliate_links(): void
    {
        $sanitizer = new HtmlSanitizer;
        $compiler = new PresellCompilerEngine($sanitizer);

        $variation = [
            'productName' => 'Alpha Boost Supplement',
            'language' => 'en',
            'strategyType' => 'flash_sale',
            'affiliateUrl' => 'https://hop.clickbank.net/?aff=myid&vendor=alphaboost',
            'trackingCode' => '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-COMPETITOR"></script><script>fbq("track", "Purchase");</script>',
            'flowhubTrackScript' => '<script async src="https://myflowhub.com/t.js" data-track="PUBLICKEY789" data-page="42"></script>',
            'editableParams' => [
                'headline' => 'Special Verified Discount',
                'ctaText' => 'Claim Allocation Now',
            ],
        ];

        $compiled = $compiler->compile($variation);

        // Competitor tracking must be stripped
        $this->assertStringNotContainsString('googletagmanager.com', $compiled);
        $this->assertStringNotContainsString('fbq("track"', $compiled);

        // Flow Hub first-party tracking must be intact
        $this->assertStringContainsString('https://myflowhub.com/t.js', $compiled);
        $this->assertStringContainsString('data-track="PUBLICKEY789"', $compiled);
        $this->assertStringContainsString('data-page="42"', $compiled);

        // Affiliate hop link and attribution forwarding JS must be intact
        $this->assertStringContainsString('hop.clickbank.net', $compiled);
        $this->assertStringContainsString('getAttributedUrl', $compiled);
        $this->assertStringContainsString('Claim Allocation Now', $compiled);
    }

    /**
     * Requirement: PageClonerService runs sanitizer before parameter pass-through and affiliate link replacement.
     */
    public function test_page_cloner_preserves_first_party_scripts_and_affiliate_rewrites(): void
    {
        $crawler = new PageCrawlerService;
        $sanitizer = new HtmlSanitizer;
        $cloner = new PageClonerService($crawler, $sanitizer);

        $dirtyHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<title>Sales Landing Page</title>
<meta http-equiv="refresh" content="5; url=https://hijack-traffic.com">
<script src="https://analytics.tiktok.com/i18n/pixel/events.js"></script>
<script>ttq.load('ABC');</script>
</head>
<body>
<h1>Product Showcase</h1>
<a href="https://vendor-checkout.com/pay" class="buy-btn">Order Now</a>
</body>
</html>
HTML;

        $cloned = $cloner->clone([
            'rawHtml' => $dirtyHtml,
            'sourceUrl' => 'https://example-offer.test/landing',
            'affiliateLink' => 'https://hop.clickbank.net/?aff=flowhub&vendor=test',
            'stripTrackers' => true,
            'enableParamPassThrough' => true,
        ]);

        $html = $cloned['clonedHtml'];

        // Competitor pixel and meta refresh stripped
        $this->assertStringNotContainsString('analytics.tiktok.com', $html);
        $this->assertStringNotContainsString('ttq.load', $html);
        $this->assertStringNotContainsString('http-equiv="refresh"', $html);

        // Affiliate link replacement intact
        $this->assertStringContainsString('https://hop.clickbank.net/?aff=flowhub', $html);
        $this->assertStringContainsString('vendor=test', $html);
        $this->assertStringContainsString('data-flowhub-affiliate="true"', $html);

        // Flow Hub parameter pass-through micro-script intact
        $this->assertStringContainsString('FlowHub Inbound Attribution & Parameter Pass-Through Engine', $html);
        $this->assertStringContainsString('syncAffiliateLinks', $html);
    }

    /**
     * Requirement: HtmlDocument sanitizes body while preserving first-party script block.
     */
    public function test_html_document_sanitizes_body_and_preserves_track_script(): void
    {
        $document = new HtmlDocument(new HtmlSanitizer);

        $dirtyBody = '<meta http-equiv="refresh" content="0; url=https://bad.com"><script src="https://www.google-analytics.com/analytics.js"></script><div>Clean Article Content</div>';
        $trackScript = '<script async src="https://flowhub.app/t.js" data-track="KEY123" data-page="99"></script>';

        $wrapped = $document->wrap($dirtyBody, 'Safe Document Title', $trackScript);

        $this->assertStringNotContainsString('http-equiv="refresh"', $wrapped);
        $this->assertStringNotContainsString('google-analytics.com', $wrapped);
        $this->assertStringContainsString('Clean Article Content', $wrapped);
        $this->assertStringContainsString($trackScript, $wrapped);
    }

    /**
     * Requirement: Controller crawlAndClone endpoint rejects SSRF targets with HTTP 422.
     */
    public function test_crawl_and_clone_controller_rejects_ssrf_urls_with_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/pages/crawl-and-clone', [
            'url' => 'http://169.254.169.254/latest/meta-data',
            'affiliate_link' => 'https://hop.clickbank.net',
            'product_name' => 'SSRF Test',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('SSRF blocked', (string) $response->json('message'));
    }
}
