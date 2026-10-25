<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PresellPageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'owner',
        ]);
    }

    public function test_authenticated_user_can_view_pages_index_with_strategies(): void
    {
        $response = $this->actingAs($this->user)->get('/pages');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pages/Index')
                ->has('strategies', 4)
                ->where('strategies.0.id', 'cookie_popup')
                ->where('strategies.1.id', 'country_select')
                ->where('strategies.2.id', 'flash_sale')
                ->where('strategies.3.id', 'ai_express')
                ->has('pages')
                ->has('tracks')
            );
    }

    public function test_generate_variations_returns_three_distinct_versions(): void
    {
        $payload = [
            'strategyType' => 'flash_sale',
            'productName' => 'Zenith Male Vitality',
            'language' => 'en',
            'affiliateUrl' => 'https://network.example.com/aff_c?offer_id=77',
            'trackingCode' => '<script>console.log("gtag");</script>',
            'seoEnabled' => true,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/pages/generate-variations', $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'variations');

        $variations = $response->json('variations');
        $this->assertEquals('v1', $variations[0]['id']);
        $this->assertEquals('v2', $variations[1]['id']);
        $this->assertEquals('v3', $variations[2]['id']);

        // Check distinct themes
        $this->assertNotEquals($variations[0]['themeConfig']['primaryColor'], $variations[1]['themeConfig']['primaryColor']);
        $this->assertNotEquals($variations[0]['editableParams']['headline'], $variations[1]['editableParams']['headline']);

        // Check compiled HTML exists for each
        foreach ($variations as $var) {
            $this->assertNotEmpty($var['compiledHtml']);
            $this->assertStringContainsString('<!DOCTYPE html>', $var['compiledHtml']);
            $this->assertStringContainsString('Zenith Male Vitality', $var['compiledHtml']);
            $this->assertStringContainsString('https://network.example.com/aff_c?offer_id=77', $var['compiledHtml']);
            $this->assertStringContainsString('gtag', $var['compiledHtml']);
            $this->assertStringContainsString('<title>', $var['compiledHtml']);
            $this->assertStringContainsString('schema.org', $var['compiledHtml']);
        }
    }

    public function test_generate_variations_in_portuguese(): void
    {
        $payload = [
            'strategyType' => 'cookie_popup',
            'productName' => 'Fimavyn Gotas',
            'language' => 'pt',
            'affiliateUrl' => 'https://brazil.example.com/checkout',
            'seoEnabled' => false,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variations = $response->json('variations');
        $this->assertStringContainsString('Desconto', $variations[0]['compiledHtml']);
        $this->assertStringContainsString('Fimavyn Gotas', $variations[0]['compiledHtml']);
        $this->assertStringContainsString('cookie_modal', $variations[0]['compiledHtml']);
    }

    public function test_country_select_presell_includes_interactive_flags(): void
    {
        $payload = [
            'strategyType' => 'country_select',
            'productName' => 'Alpha Cleanse',
            'language' => 'en',
            'affiliateUrl' => 'https://offer.example.com/link',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variations = $response->json('variations');
        $html = $variations[0]['compiledHtml'];

        $this->assertStringContainsString('United States', $html);
        $this->assertStringContainsString('Canada', $html);
        $this->assertStringContainsString('data-country="US"', $html);
        $this->assertStringContainsString('data-country="CA"', $html);
    }

    public function test_compile_endpoint_instantaneously_recompiles_with_overrides(): void
    {
        // First get a variation
        $genResponse = $this->actingAs($this->user)
            ->postJson('/pages/generate-variations', [
                'strategyType' => 'flash_sale',
                'productName' => 'Keto Power',
                'language' => 'en',
                'affiliateUrl' => 'https://keto.example.com',
            ]);

        $variation = $genResponse->json('variations.0');

        // Modify editable parameters
        $variation['editableParams']['headline'] = 'Exclusive 70% Promotional Allocation Unlocked Today';
        $variation['editableParams']['discountPercentage'] = '70% OFF';
        $variation['editableParams']['timerMinutes'] = 7;

        $compileResponse = $this->actingAs($this->user)
            ->postJson('/pages/compile', [
                'variation' => $variation,
            ]);

        $compileResponse->assertOk()
            ->assertJsonPath('success', true);

        $html = $compileResponse->json('compiledHtml');
        $this->assertStringContainsString('Exclusive 70% Promotional Allocation Unlocked Today', $html);
        $this->assertStringContainsString('70% OFF', $html);
        $this->assertStringContainsString('totalSeconds = 420', $html); // 7 * 60 = 420
    }

    public function test_google_ads_auction_uniqueness_varies_class_prefixes(): void
    {
        $genResponse = $this->actingAs($this->user)
            ->postJson('/pages/generate-variations', [
                'strategyType' => 'flash_sale',
                'productName' => 'Prostagen Pro',
                'language' => 'en',
                'affiliateUrl' => 'https://test.example.com',
            ]);

        $v1Html = $genResponse->json('variations.0.compiledHtml');
        $v2Html = $genResponse->json('variations.1.compiledHtml');

        // Extract class prefixes
        preg_match('/class="(fp_[a-z0-9]+_)body"/', $v1Html, $m1);
        preg_match('/class="(fp_[a-z0-9]+_)body"/', $v2Html, $m2);

        $this->assertNotEmpty($m1[1]);
        $this->assertNotEmpty($m2[1]);
        $this->assertNotEquals($m1[1], $m2[1]);
    }

    public function test_user_can_save_compiled_presell_page(): void
    {
        $track = Track::query()->create([
            'product_name' => 'Existing Track Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test&vendor=test',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);

        $response = $this->actingAs($this->user)
            ->post('/pages', [
                'name' => 'My Tested Presell Page',
                'track_id' => $track->id,
                'page_type' => 'flash_sale',
                'language' => 'en',
                'html' => '<!DOCTYPE html><html><body>Test Presell Content</body></html>',
                'variation' => ['test' => true],
            ]);

        $response->assertRedirect('/pages')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('pages', [
            'name' => 'My Tested Presell Page',
            'track_id' => $track->id,
            'page_type' => 'flash_sale',
        ]);
    }

    public function test_direct_export_returns_html_download(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/pages/export-direct', [
                'html' => '<!DOCTYPE html><html><head><title>Direct</title></head><body>Direct Export</body></html>',
            ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="index.html"');

        $this->assertStringContainsString('Direct Export', $response->getContent());
    }

    public function test_generate_variations_supports_only_google_global_tag(): void
    {
        $payload = [
            'strategyType' => 'flash_sale',
            'productName' => 'Global Tag Only Offer',
            'language' => 'en',
            'affiliateUrl' => 'https://example.com/hop',
            'googleGlobalTag' => '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-111111111"></script><script>gtag("config", "AW-111111111");</script>',
            'googleActionTag' => null,
            'trackingCode' => null,
            'seoEnabled' => false,
        ];

        $response = $this->actingAs($this->user)->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variation = $response->json('variations.0');
        $html = $variation['compiledHtml'];

        $this->assertStringContainsString('AW-111111111', $html);
        $this->assertStringContainsString('<head>', $html);
        // Ensure it is inside the <head> element before </head>
        $headSection = explode('</head>', $html)[0];
        $this->assertStringContainsString('AW-111111111', $headSection);
    }

    public function test_generate_variations_supports_only_google_action_tag(): void
    {
        $payload = [
            'strategyType' => 'flash_sale',
            'productName' => 'Action Tag Only Offer',
            'language' => 'en',
            'affiliateUrl' => 'https://example.com/hop',
            'googleGlobalTag' => null,
            'googleActionTag' => '<script>gtag("event", "conversion", {"send_to": "AW-222222222/abc"});</script>',
            'trackingCode' => null,
            'seoEnabled' => false,
        ];

        $response = $this->actingAs($this->user)->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variation = $response->json('variations.0');
        $html = $variation['compiledHtml'];

        $headSection = explode('</head>', $html)[0];
        $this->assertStringContainsString('AW-222222222/abc', $headSection);
    }

    public function test_generate_variations_supports_both_google_tags_and_additional_scripts(): void
    {
        $payload = [
            'strategyType' => 'cookie_popup',
            'productName' => 'Dual Google Tags Offer',
            'language' => 'en',
            'affiliateUrl' => 'https://example.com/hop',
            'googleGlobalTag' => '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-333333333"></script>',
            'googleActionTag' => '<script>gtag("event", "conversion", {"send_to": "AW-333333333/xyz"});</script>',
            'trackingCode' => '<script>console.log("additional_meta_script");</script>',
            'seoEnabled' => false,
        ];

        $response = $this->actingAs($this->user)->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variation = $response->json('variations.0');
        $html = $variation['compiledHtml'];

        $headSection = explode('</head>', $html)[0];
        $this->assertStringContainsString('AW-333333333', $headSection);
        $this->assertStringContainsString('AW-333333333/xyz', $headSection);
        $this->assertStringContainsString('additional_meta_script', $headSection);
    }

    public function test_generate_variations_supports_neither_tag(): void
    {
        $payload = [
            'strategyType' => 'flash_sale',
            'productName' => 'No Tags Offer',
            'language' => 'en',
            'affiliateUrl' => 'https://example.com/hop',
            'googleGlobalTag' => null,
            'googleActionTag' => null,
            'trackingCode' => null,
            'seoEnabled' => false,
        ];

        $response = $this->actingAs($this->user)->postJson('/pages/generate-variations', $payload);

        $response->assertOk();
        $variation = $response->json('variations.0');
        $html = $variation['compiledHtml'];

        $headSection = explode('</head>', $html)[0];
        $this->assertStringNotContainsString('AW-', $headSection);
    }
}
