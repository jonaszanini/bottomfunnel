<?php

namespace Tests\Feature;

use App\Enums\AffiliateApplicationStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliateApplicationStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'owner',
        ]);

        $this->seed(NegativeKeywordSeedSeeder::class);
    }

    public function test_default_status_is_not_requested(): void
    {
        $product = WatchedProduct::factory()->create();

        $this->assertSame(AffiliateApplicationStatus::NotRequested, $product->affiliate_application_status);
        $this->assertEquals('not_requested', $product->affiliate_application_status->value);
        $this->assertEquals('Não solicitado', $product->affiliate_application_status->label());
        $this->assertNull($product->affiliate_application_requested_at);
    }

    public function test_valid_update_saves_status_and_date(): void
    {
        $product = WatchedProduct::factory()->create([
            'affiliate_application_status' => AffiliateApplicationStatus::NotRequested,
            'affiliate_application_requested_at' => null,
        ]);

        $response = $this->actingAs($this->user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => 'approved',
            'affiliate_application_requested_at' => '2026-10-15',
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertSame(AffiliateApplicationStatus::Approved, $product->affiliate_application_status);
        $this->assertEquals('approved', $product->affiliate_application_status->value);
        $this->assertEquals('Aprovado', $product->affiliate_application_status->label());
        $this->assertNotNull($product->affiliate_application_requested_at);
        $this->assertEquals('2026-10-15', $product->affiliate_application_requested_at->format('Y-m-d'));
    }

    public function test_invalid_status_returns_422(): void
    {
        $product = WatchedProduct::factory()->create();

        $response = $this->actingAs($this->user)->putJson("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => 'invalid_status_value',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('affiliate_application_status');
    }

    public function test_changing_to_pending_without_date_fills_today_toronto(): void
    {
        $product = WatchedProduct::factory()->create([
            'affiliate_application_status' => AffiliateApplicationStatus::NotRequested,
            'affiliate_application_requested_at' => null,
        ]);

        $response = $this->actingAs($this->user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => 'pending',
            'affiliate_application_requested_at' => '',
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertSame(AffiliateApplicationStatus::Pending, $product->affiliate_application_status);
        $expectedDate = now('America/Toronto')->toDateString();
        $this->assertNotNull($product->affiliate_application_requested_at);
        $this->assertEquals($expectedDate, $product->affiliate_application_requested_at->format('Y-m-d'));

        // Switching back to not_requested keeps the date provided by the user
        $this->actingAs($this->user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => 'not_requested',
            'affiliate_application_requested_at' => '2026-05-01',
        ]);

        $product->refresh();
        $this->assertSame(AffiliateApplicationStatus::NotRequested, $product->affiliate_application_status);
        $this->assertEquals('2026-05-01', $product->affiliate_application_requested_at->format('Y-m-d'));
    }

    public function test_index_filter_returns_only_products_of_that_status(): void
    {
        $pendingProduct = WatchedProduct::factory()->create([
            'name' => 'Pending Alpha Offer',
            'affiliate_application_status' => AffiliateApplicationStatus::Pending,
        ]);

        $approvedProduct = WatchedProduct::factory()->create([
            'name' => 'Approved Beta Offer',
            'affiliate_application_status' => AffiliateApplicationStatus::Approved,
        ]);

        $notRequestedProduct = WatchedProduct::factory()->create([
            'name' => 'Not Requested Gamma Offer',
            'affiliate_application_status' => AffiliateApplicationStatus::NotRequested,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products?affiliate_application_status=pending');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.id', $pendingProduct->id)
                ->where('products.0.name', 'Pending Alpha Offer')
                ->where('products.0.affiliate_application_status', 'pending')
                ->where('products.0.affiliate_application_status_label', 'Pendente')
                ->has('options.affiliate_application_statuses')
            );

        $responseApproved = $this->actingAs($this->user)
            ->get('/watching-products?affiliate_application_status=approved');

        $responseApproved->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.id', $approvedProduct->id)
                ->where('products.0.affiliate_application_status', 'approved')
                ->where('products.0.affiliate_application_status_label', 'Aprovado')
            );
    }

    public function test_score_does_not_change_when_only_affiliate_application_status_is_changed(): void
    {
        $product = WatchedProduct::factory()->create([
            'score' => 88,
            'rank' => 2,
            'affiliate_application_status' => AffiliateApplicationStatus::NotRequested,
        ]);

        // Direct Eloquent update
        $product->update([
            'affiliate_application_status' => AffiliateApplicationStatus::Pending,
        ]);

        $product->refresh();
        $this->assertEquals(88, $product->score);
        $this->assertEquals(2, $product->rank);

        // Controller update without changing scoring fields
        $this->actingAs($this->user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'affiliate_application_status' => 'approved',
            'affiliate_application_requested_at' => '2026-10-30',
        ]);

        $product->refresh();
        $this->assertEquals(88, $product->score);
        $this->assertEquals(2, $product->rank);
    }
}
