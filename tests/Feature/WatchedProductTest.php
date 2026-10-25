<?php

namespace Tests\Feature;

use App\Enums\BrandBiddingStatus;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductTest extends TestCase
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

    public function test_guest_cannot_access_watching_products(): void
    {
        $this->get('/watching-products')
            ->assertRedirect('/login');

        $this->post('/watching-products', [
            'name' => 'Test Product',
        ])->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_watching_products_index(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'Fimavyn Metabolic Support',
            'status' => WatchedProductStatus::Test,
            'payout_cad' => 125.50,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Fimavyn Metabolic Support')
                ->where('products.0.status', 'test')
                ->where('products.0.payout_cad', 125.50)
                ->has('metrics')
                ->has('filters')
                ->has('options')
            );
    }

    public function test_filtering_by_status(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'Test Item',
            'status' => WatchedProductStatus::Test,
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Watch Item',
            'status' => WatchedProductStatus::Watch,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products?status=test');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Test Item')
            );
    }

    public function test_search_is_partial_case_insensitive_and_combines_with_platform(): void
    {
        WatchedProduct::factory()->create(['name' => 'DentaVive', 'platform' => 'ClickBank', 'platforms' => ['ClickBank']]);
        WatchedProduct::factory()->create(['name' => 'Denta Other', 'platform' => 'BuyGoods', 'platforms' => ['BuyGoods']]);
        WatchedProduct::factory()->create(['name' => 'SootheSmart', 'platform' => 'ClickBank', 'platforms' => ['ClickBank']]);

        $this->actingAs($this->user)
            ->get('/watching-products?search=denta')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 2));

        $this->actingAs($this->user)
            ->get('/watching-products?search=denta&platform=ClickBank')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)
                ->where('products.0.name', 'DentaVive')
            );
    }

    public function test_search_by_product_name(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'Alpha Boost Male Vitality',
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Beta Brain Focus',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products?search=Alpha');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Alpha Boost Male Vitality')
            );
    }

    public function test_filtering_by_brand_bidding_and_true_bof(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'Product 1',
            'brand_bidding' => BrandBiddingStatus::Allowed,
            'true_bof' => TrueBofStatus::True,
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Product 2',
            'brand_bidding' => BrandBiddingStatus::Banned,
            'true_bof' => TrueBofStatus::False,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products?brand_bidding=allowed&true_bof=true');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Product 1')
            );
    }

    public function test_user_can_create_watched_product_with_comma_separated_fields(): void
    {
        $payload = [
            'name' => 'Fimavyn Metabolic Support',
            'status' => 'test',
            'platforms' => 'SmartAdv, ClickBank',
            'payout_cad' => 125.00,
            'payout_notes' => 'Tiered: $40/1, $100/3, $150/6 bottles',
            'page_type' => 'TSL',
            'brand_bidding' => 'allowed',
            'true_bof' => 'true',
            'geos' => 'US, CA, UK',
            'negatives' => 'login, scam, review, amazon',
            'approval_required' => true,
            'demand_notes' => 'Strong search trends on product name.',
            'sales_page_url' => 'https://example.com/fimavyn',
            'affiliate_link' => 'https://smartadv.example.com/aff_c?offer_id=99',
            'notes' => 'Promising angle with good commissions.',
        ];

        $response = $this->actingAs($this->user)
            ->post('/watching-products', $payload);

        $response->assertRedirect('/watching-products')
            ->assertSessionHas('success');

        $product = WatchedProduct::query()->where('name', 'Fimavyn Metabolic Support')->first();
        $this->assertNotNull($product);
        $this->assertEquals(WatchedProductStatus::Test, $product->status);
        $this->assertEquals(['SmartAdv', 'ClickBank'], $product->platforms);
        $this->assertEquals(['US', 'CA', 'UK'], $product->geos);
        $this->assertEquals('125.00', $product->payout_cad);
        $this->assertTrue($product->approval_required);
    }

    public function test_user_can_update_watched_product(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Old Name',
            'status' => WatchedProductStatus::Watch,
            'payout_cad' => 50.00,
        ]);

        $response = $this->actingAs($this->user)
            ->put("/watching-products/{$product->id}", [
                'name' => 'Updated Name',
                'status' => 'test',
                'brand_bidding' => 'confirm_with_am',
                'true_bof' => 'ambiguous',
                'payout_cad' => 75.00,
            ]);

        $response->assertRedirect('/watching-products')
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertEquals('Updated Name', $product->name);
        $this->assertEquals(WatchedProductStatus::Test, $product->status);
        $this->assertEquals(BrandBiddingStatus::ConfirmWithAm, $product->brand_bidding);
        $this->assertEquals('75.00', $product->payout_cad);
    }

    public function test_user_can_quick_update_status(): void
    {
        $product = WatchedProduct::factory()->create([
            'status' => WatchedProductStatus::Watch,
        ]);

        $response = $this->actingAs($this->user)
            ->patch("/watching-products/{$product->id}/status", [
                'status' => 'rejected',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertEquals(WatchedProductStatus::Rejected, $product->status);
    }

    public function test_user_can_mark_product_as_reviewed(): void
    {
        $product = WatchedProduct::factory()->create([
            'last_reviewed_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/watching-products/{$product->id}/review");

        $response->assertRedirect()
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertNotNull($product->last_reviewed_at);
        $this->assertTrue($product->last_reviewed_at->isToday());
    }

    public function test_user_can_delete_watched_product(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Product To Delete',
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/watching-products/{$product->id}");

        $response->assertRedirect('/watching-products')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('watched_products', [
            'id' => $product->id,
        ]);
    }
}
