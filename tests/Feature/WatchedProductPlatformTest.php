<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BrandBiddingStatus;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductPlatformTest extends TestCase
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

    public function test_create_saves_platform_and_syncs_platforms(): void
    {
        $payload = [
            'name' => 'Fimavyn Metabolic Support',
            'status' => 'test',
            'platform' => 'ClickBank',
            'brand_bidding' => 'allowed',
            'true_bof' => 'true',
        ];

        $response = $this->actingAs($this->user)
            ->post('/watching-products', $payload);

        $response->assertRedirect('/watching-products')
            ->assertSessionHas('success');

        $product = WatchedProduct::query()->where('name', 'Fimavyn Metabolic Support')->firstOrFail();
        $this->assertSame('ClickBank', $product->platform);
        $this->assertSame(['ClickBank'], $product->platforms);
    }

    public function test_update_saves_platform_and_syncs_platforms(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Keto Extreme Burn',
            'platform' => 'ClickBank',
            'platforms' => ['ClickBank'],
            'status' => WatchedProductStatus::Watch,
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'true_bof' => TrueBofStatus::True,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/watching-products/{$product->id}", [
                'name' => 'Keto Extreme Burn (Updated)',
                'status' => 'test',
                'platform' => 'Digistore24',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
            ]);

        $response->assertRedirect();
        $fresh = $product->fresh();
        $this->assertSame('Digistore24', $fresh->platform);
        $this->assertContains('ClickBank', $fresh->platforms);
        $this->assertContains('Digistore24', $fresh->platforms);
    }

    public function test_value_outside_platform_list_is_rejected(): void
    {
        $payload = [
            'name' => 'Invalid Platform Product',
            'status' => 'test',
            'platform' => 'FakePlatformNotAllowed',
            'brand_bidding' => 'allowed',
            'true_bof' => 'true',
        ];

        $response = $this->actingAs($this->user)
            ->post('/watching-products', $payload);

        $response->assertSessionHasErrors(['platform']);

        $product = WatchedProduct::factory()->create([
            'name' => 'Valid Product',
            'platform' => 'ClickBank',
            'status' => WatchedProductStatus::Watch,
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'true_bof' => TrueBofStatus::True,
        ]);

        $responseUpdate = $this->actingAs($this->user)
            ->put("/watching-products/{$product->id}", [
                'name' => 'Valid Product',
                'status' => 'test',
                'platform' => 'NotAnAcceptedPlatform',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
            ]);

        $responseUpdate->assertSessionHasErrors(['platform']);
    }

    public function test_platform_filter_still_returns_right_products(): void
    {
        $cbProduct = WatchedProduct::factory()->create([
            'name' => 'ClickBank Product',
            'platform' => 'ClickBank',
            'platforms' => ['ClickBank'],
        ]);

        $bgProduct = WatchedProduct::factory()->create([
            'name' => 'BuyGoods Product',
            'platform' => 'BuyGoods',
            'platforms' => ['BuyGoods'],
        ]);

        $multiProduct = WatchedProduct::factory()->create([
            'name' => 'Multi Platform Product',
            'platform' => 'MaxWeb',
            'platforms' => ['ClickBank', 'MaxWeb'],
        ]);

        // Filter by ClickBank should match cbProduct and multiProduct
        $response = $this->actingAs($this->user)
            ->get('/watching-products?platform=ClickBank');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 2)
                ->where('products.0.name', fn ($name) => in_array($name, ['ClickBank Product', 'Multi Platform Product'], true))
            );

        // Filter by BuyGoods should match bgProduct only
        $responseBg = $this->actingAs($this->user)
            ->get('/watching-products?platform=BuyGoods');

        $responseBg->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'BuyGoods Product')
            );
    }

    public function test_backfill_covers_case_a_single_platform_item(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product Case A',
            'platform' => null,
            'platforms' => ['Affplus'],
            'sales_page_url' => null,
            'affiliate_link' => null,
        ]);

        $this->artisan('watching:backfill-platform')
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('Affplus', $fresh->platform);
        $this->assertSame(['Affplus'], $fresh->platforms);
    }

    public function test_backfill_covers_case_b_each_domain(): void
    {
        $cases = [
            ['https://hop.clickbank.net/?aff=test', 'ClickBank'],
            ['https://sub.hop.clickbank.net/?aff=test', 'ClickBank'],
            ['https://clickbank.net/offer', 'ClickBank'],
            ['https://clickbank.com/product', 'ClickBank'],
            ['https://digistore24.com/redir/123', 'Digistore24'],
            ['https://buygoods.com/aff', 'BuyGoods'],
            ['https://maxweb.com/offer/456', 'MaxWeb'],
            ['https://affplus.com/deal/789', 'Affplus'],
            ['https://track.smartadv.com/click', 'SmartAdv'],
        ];

        foreach ($cases as $index => [$url, $expectedPlatform]) {
            $product = WatchedProduct::factory()->create([
                'name' => "Product Case B {$index}",
                'platform' => null,
                'platforms' => [],
                'sales_page_url' => $url,
                'affiliate_link' => null,
            ]);

            $this->artisan('watching:backfill-platform')
                ->assertSuccessful();

            $fresh = $product->fresh();
            $this->assertSame($expectedPlatform, $fresh->platform, "Failed asserting {$url} matches {$expectedPlatform}");
            $this->assertSame([$expectedPlatform], $fresh->platforms);
        }
    }

    public function test_backfill_covers_case_b_via_affiliate_link_fallback(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product Affiliate Link Fallback',
            'platform' => null,
            'platforms' => [],
            'sales_page_url' => 'https://generic-vendor-page.com',
            'affiliate_link' => 'https://maxweb.com/offer/999',
        ]);

        $this->artisan('watching:backfill-platform')
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('MaxWeb', $fresh->platform);
    }

    public function test_backfill_covers_case_c_uninferrable_fallback(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product Case C',
            'platform' => null,
            'platforms' => [],
            'sales_page_url' => 'https://example-random-site.com/lander',
            'affiliate_link' => null,
        ]);

        $this->artisan('watching:backfill-platform')
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertSame('Unknown', $fresh->platform);
        $this->assertSame(['Unknown'], $fresh->platforms);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product Dry Run',
            'platform' => null,
            'platforms' => ['Digistore24'],
        ]);

        $this->artisan('watching:backfill-platform --dry-run')
            ->assertSuccessful();

        $fresh = $product->fresh();
        $this->assertNull($fresh->platform);
    }

    public function test_backfill_is_idempotent(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product Idempotent',
            'platform' => null,
            'platforms' => [],
            'sales_page_url' => 'https://buygoods.com/checkout',
        ]);

        // First run backfills
        $this->artisan('watching:backfill-platform')
            ->assertSuccessful();

        $this->assertSame('BuyGoods', $product->fresh()->platform);

        // Second run should execute cleanly without modifying or failing
        $this->artisan('watching:backfill-platform')
            ->assertSuccessful()
            ->expectsOutput('No watched products require platform backfill.');

        $this->assertSame('BuyGoods', $product->fresh()->platform);
    }

    public function test_index_and_show_include_platform_in_inertia_props(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Props Test Product',
            'platform' => 'ClickBank',
            'platforms' => ['ClickBank'],
        ]);

        // Test Index
        $responseIndex = $this->actingAs($this->user)
            ->get('/watching-products');

        $responseIndex->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.platform', 'ClickBank')
                ->has('options.platforms')
                ->where('options.platforms', fn ($platforms) => collect($platforms)->contains('ClickBank'))
            );

        // Test Show
        $responseShow = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $responseShow->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Show')
                ->where('product.platform', 'ClickBank')
                ->has('options.platforms')
                ->where('options.platforms', fn ($platforms) => collect($platforms)->contains('ClickBank'))
            );
    }
}
