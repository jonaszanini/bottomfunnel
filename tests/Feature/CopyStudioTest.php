<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Copy\CopyCharacterValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CopyStudioTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'username' => 'copy_specialist',
        ]);
    }

    public function test_authenticated_user_can_view_copy_studio(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/copy?url=https://example.com/offer&product=TestProduct&lang=en');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Copy/Index')
                ->where('initialUrl', 'https://example.com/offer')
                ->where('initialProduct', 'TestProduct')
                ->where('initialLang', 'en')
            );
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/copy');
        $response->assertRedirect('/login');
    }

    public function test_generate_base_copy_with_successful_scrape(): void
    {
        $mockHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Zenith Male Vitality - Official Manufacturer Store</title>
    <meta name="description" content="Authentic Zenith Male Vitality formula with priority express shipping and 60-day money-back guarantee.">
</head>
<body>
    <h1>Authentic Zenith Male Vitality Direct Allocation</h1>
    <h2>GMP Certified Facility Quality Standards</h2>
    <p>Try completely risk-free with our ironclad 60-day money-back guarantee. If you are not satisfied, get a full refund.</p>
    <ul>
        <li>100% Authentic Direct Sourcing</li>
        <li>Free Digital Bonus Guide with Every Multi-Bottle Order</li>
        <li>256-Bit Bank-Grade SSL Secure Checkout</li>
    </ul>
</body>
</html>
HTML;

        Http::fake([
            'https://zenithvitality.com*' => Http::response($mockHtml, 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/copy/generate-base', [
                'salesPageUrl' => 'https://zenithvitality.com/offer',
                'targetKeyword' => 'Zenith Male Vitality',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('detectedLanguage', 'en')
            ->assertJsonPath('scraper.isFallback', false);

        $headlines = $response->json('headlines');
        $descriptions = $response->json('descriptions');

        // Check exact asset counts
        $this->assertCount(20, $headlines);
        $this->assertCount(10, $descriptions);

        // Verify strict character length and Google Ads compliance
        foreach ($headlines as $h) {
            $this->assertLessThanOrEqual(30, mb_strlen($h['text']), "Headline exceeds 30 chars: {$h['text']}");
            $this->assertStringNotContainsString('!', $h['text'], "Headline contains '!': {$h['text']}");
            $this->assertContains($h['category'], ['Authority', 'Guarantee', 'Bonus', 'Benefit', 'Security']);
            $this->assertFalse($h['isLocked']);
        }

        foreach ($descriptions as $d) {
            $this->assertLessThanOrEqual(90, mb_strlen($d['text']), "Description exceeds 90 chars: {$d['text']}");
            $this->assertNotEmpty($d['angle']);
            $this->assertFalse($d['isLocked']);
        }
    }

    public function test_generate_base_copy_falls_back_gracefully_when_blocked(): void
    {
        Http::fake([
            'https://blocked-site.com*' => Http::response('Cloudflare 403 Forbidden', 403),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/copy/generate-base', [
                'salesPageUrl' => 'https://blocked-site.com/offer',
                'manualContext' => "ProstaShield Male Formula\n60-day money-back guarantee.\nFree bonus guide with 3 bottles.\n100% secure SSL encrypted checkout.",
                'targetKeyword' => 'ProstaShield',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('scraper.isFallback', true);

        $this->assertNotEmpty($response->json('scraper.warning'));
        $this->assertCount(20, $response->json('headlines'));
        $this->assertCount(10, $response->json('descriptions'));
    }

    public function test_shorten_endpoint_compresses_headline_under_30_chars(): void
    {
        $longHeadline = 'This Is A Very Long Headline That Definitely Exceeds Thirty Characters';
        $this->assertGreaterThan(30, mb_strlen($longHeadline));

        $response = $this->actingAs($this->user)
            ->postJson('/api/copy/shorten', [
                'text' => $longHeadline,
                'type' => 'headline',
                'maxChars' => 30,
                'language' => 'en',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $shortened = $response->json('text');
        $this->assertLessThanOrEqual(30, mb_strlen($shortened));
        $this->assertStringNotContainsString('!', $shortened);
    }

    public function test_shorten_endpoint_compresses_description_under_90_chars(): void
    {
        $longDescription = 'This is an extremely long and wordy description designed specifically to exceed the ninety character maximum limit allowed by Google Ads policies and requirements.';
        $this->assertGreaterThan(90, mb_strlen($longDescription));

        $response = $this->actingAs($this->user)
            ->postJson('/api/copy/shorten', [
                'text' => $longDescription,
                'type' => 'description',
                'maxChars' => 90,
                'language' => 'en',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $shortened = $response->json('text');
        $this->assertLessThanOrEqual(90, mb_strlen($shortened));
    }

    public function test_refine_with_screenshot_preserves_locked_items_and_refines_unlocked(): void
    {
        $headlines = [
            ['id' => 1, 'text' => 'My Locked Authority Headline', 'category' => 'Authority', 'isLocked' => true],
            ['id' => 2, 'text' => 'Generic Money Back Deal', 'category' => 'Guarantee', 'isLocked' => false],
            ['id' => 3, 'text' => 'Free Bonus Book Included', 'category' => 'Bonus', 'isLocked' => false],
        ];

        $descriptions = [
            ['id' => 1, 'text' => 'My locked description text that must not change during competitor refinement.', 'angle' => 'Locked Angle', 'isLocked' => true],
            ['id' => 2, 'text' => 'Generic description about the product and discount pricing available.', 'angle' => 'Offer', 'isLocked' => false],
        ];

        $fakeBase64 = base64_encode('fake-image-bytes');

        $response = $this->actingAs($this->user)
            ->postJson('/api/copy/refine-with-screenshot', [
                'screenshotBase64' => $fakeBase64,
                'currentHeadlines' => $headlines,
                'currentDescriptions' => $descriptions,
                'productSummary' => 'Authentic male vitality supplement offer.',
                'detectedLanguage' => 'en',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $refinedHeadlines = $response->json('headlines');
        $refinedDescriptions = $response->json('descriptions');
        $summary = $response->json('competitorAnalysisSummary');

        // Verify 3 bullet points summary
        $this->assertCount(3, $summary);

        // Verify locked items stayed 100% identical
        $h1 = collect($refinedHeadlines)->firstWhere('id', 1);
        $this->assertEquals('My Locked Authority Headline', $h1['text']);
        $this->assertTrue($h1['isLocked']);

        $d1 = collect($refinedDescriptions)->firstWhere('id', 1);
        $this->assertEquals('My locked description text that must not change during competitor refinement.', $d1['text']);
        $this->assertTrue($d1['isLocked']);

        // Verify all headlines and descriptions are <= 30 and <= 90
        foreach ($refinedHeadlines as $h) {
            $this->assertLessThanOrEqual(30, mb_strlen($h['text']));
            $this->assertStringNotContainsString('!', $h['text']);
        }

        foreach ($refinedDescriptions as $d) {
            $this->assertLessThanOrEqual(90, mb_strlen($d['text']));
        }
    }

    public function test_copy_character_validator_sanitizes_policy_and_smart_trims(): void
    {
        $validator = app(CopyCharacterValidator::class);

        // 1. Headline exclamation marks stripped
        $this->assertEquals(
            'Official Store Guarantee Now',
            $validator->sanitizeGoogleAdsText('Official Store! Guarantee Now!!!', 'headline')
        );

        // 2. Description allows single exclamation, subsequent converted to period
        $this->assertEquals(
            'Special offer today! Fast shipping. Order now.',
            $validator->sanitizeGoogleAdsText('Special offer today! Fast shipping! Order now!', 'description')
        );

        // 3. ALL CAPS words normalized unless short acronym
        $this->assertEquals(
            'Order FDA Approved Formula Today',
            $validator->sanitizeGoogleAdsText('ORDER FDA APPROVED FORMULA TODAY', 'headline')
        );

        // 4. Deterministic smart trim removes trailing connectors
        $trimmed = $validator->deterministicSmartTrim('Get your authentic batch today with', 30);
        $this->assertStringEndsNotWith('with', $trimmed);
        $this->assertLessThanOrEqual(30, mb_strlen($trimmed));
    }
}
