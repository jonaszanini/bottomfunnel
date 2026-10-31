<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductLinksTest extends TestCase
{
    use RefreshDatabase;

    private function base(): array
    {
        return ['name' => 'X', 'status' => 'test', 'brand_bidding' => 'confirm_with_am', 'true_bof' => 'ambiguous', 'payout_cad' => 10];
    }

    public function test_network_offer_url_saves_and_is_exposed(): void
    {
        $user = User::factory()->create();
        $product = WatchedProduct::factory()->create();

        $this->actingAs($user)->put("/watching-products/{$product->id}", $this->base() + [
            'network_offer_url' => 'https://portal.smartadv.com/offers/16583',
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://portal.smartadv.com/offers/16583', $product->refresh()->network_offer_url);

        $this->actingAs($user)->get("/watching-products/{$product->id}")
            ->assertInertia(fn (Assert $page) => $page->where('product.network_offer_url', 'https://portal.smartadv.com/offers/16583')->etc());
    }

    public function test_network_offer_url_must_be_url(): void
    {
        $user = User::factory()->create();
        $product = WatchedProduct::factory()->create();

        $this->actingAs($user)->put("/watching-products/{$product->id}", $this->base() + [
            'network_offer_url' => 'not a url',
        ])->assertSessionHasErrors('network_offer_url');
    }

    public function test_show_exposes_stored_application_status_for_edit_form(): void
    {
        $user = User::factory()->create();
        $product = WatchedProduct::factory()->create([
            'affiliate_application_status' => 'approved',
            'affiliate_application_requested_at' => '2026-10-31',
        ]);

        $this->actingAs($user)->get("/watching-products/{$product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.affiliate_application_status', 'approved')
                ->where('product.affiliate_application_requested_at', '2026-10-31')
                ->etc());
    }
}
