<?php

namespace Tests\Feature;

use App\Enums\ComplianceRisk;
use App\Enums\WatchedProductStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductConstraintsTest extends TestCase
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

    public function test_can_save_and_retrieve_offer_constraints_on_model(): void
    {
        $product = WatchedProduct::create([
            'name' => 'Prostate Flux Ultra',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 65.00,
            'payout_currency' => 'USD',
            'allowed_geos' => ['US', 'CA', 'AU'],
            'compliance_risk' => ComplianceRisk::Restricted,
            'compliance_notes' => 'Contains saw palmetto and testosterone claims. Restricted under Google Ads health policies.',
            'network_approval_required' => true,
            'payouts_by_platform' => [
                ['platform' => 'clickbank', 'payout' => 48.50, 'currency' => 'USD'],
                ['platform' => 'buygoods', 'payout' => 52.00, 'currency' => 'USD'],
            ],
        ]);

        $fresh = WatchedProduct::findOrFail($product->id);

        $this->assertEquals(['US', 'CA', 'AU'], $fresh->allowed_geos);
        $this->assertSame(ComplianceRisk::Restricted, $fresh->compliance_risk);
        $this->assertEquals('Restricted', $fresh->compliance_risk->label());
        $this->assertTrue($fresh->compliance_risk->isRestricted());
        $this->assertEquals('Contains saw palmetto and testosterone claims. Restricted under Google Ads health policies.', $fresh->compliance_notes);
        $this->assertTrue($fresh->network_approval_required);
        $this->assertCount(2, $fresh->payouts_by_platform);
        $this->assertEquals('clickbank', $fresh->payouts_by_platform[0]['platform']);
        $this->assertEquals(48.50, $fresh->payouts_by_platform[0]['payout']);
        $this->assertEquals('USD', $fresh->payouts_by_platform[0]['currency']);
    }

    public function test_can_create_product_with_offer_constraints_via_controller(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/watching-products', [
                'name' => 'Keto Power Trim',
                'status' => 'watch',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
                'payout_amount' => 55.00,
                'payout_currency' => 'USD',
                'allowed_geos' => ['US', 'GB', 'NZ'],
                'compliance_risk' => 'medium',
                'compliance_notes' => 'Standard keto weight loss disclaimers required.',
                'network_approval_required' => false,
                'payouts_by_platform' => [
                    ['platform' => 'ClickBank', 'payout' => 55.00, 'currency' => 'USD'],
                ],
            ]);

        $response->assertRedirect('/watching-products');

        $this->assertDatabaseHas('watched_products', [
            'name' => 'Keto Power Trim',
            'compliance_risk' => 'medium',
            'network_approval_required' => false,
            'compliance_notes' => 'Standard keto weight loss disclaimers required.',
        ]);

        $product = WatchedProduct::where('name', 'Keto Power Trim')->firstOrFail();
        $this->assertEquals(['US', 'GB', 'NZ'], $product->allowed_geos);
        $this->assertCount(1, $product->payouts_by_platform);
        $this->assertEquals('ClickBank', $product->payouts_by_platform[0]['platform']);
    }

    public function test_can_update_product_offer_constraints_via_controller(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Initial Offer Name',
            'compliance_risk' => ComplianceRisk::Low,
            'network_approval_required' => false,
            'allowed_geos' => ['US'],
            'payouts_by_platform' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->put("/watching-products/{$product->id}", [
                'name' => 'Initial Offer Name',
                'status' => 'test',
                'brand_bidding' => 'allowed',
                'true_bof' => 'true',
                'payout_amount' => 80.00,
                'payout_currency' => 'USD',
                'allowed_geos' => ['US', 'CA', 'GB', 'AU'],
                'compliance_risk' => 'restricted',
                'compliance_notes' => 'ED niche requires presell bridge page without direct claims.',
                'network_approval_required' => true,
                'payouts_by_platform' => [
                    ['platform' => 'MaxWeb', 'payout' => 85.00, 'currency' => 'USD'],
                    ['platform' => 'Digistore24', 'payout' => 78.50, 'currency' => 'USD'],
                ],
            ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertSame(ComplianceRisk::Restricted, $product->compliance_risk);
        $this->assertTrue($product->network_approval_required);
        $this->assertEquals('ED niche requires presell bridge page without direct claims.', $product->compliance_notes);
        $this->assertEquals(['US', 'CA', 'GB', 'AU'], $product->allowed_geos);
        $this->assertCount(2, $product->payouts_by_platform);
        $this->assertEquals('MaxWeb', $product->payouts_by_platform[0]['platform']);
        $this->assertEquals(85.00, $product->payouts_by_platform[0]['payout']);
    }

    public function test_detail_page_receives_the_new_data(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Testosterone Prime Booster',
            'status' => WatchedProductStatus::Test,
            'payout_amount' => 90.00,
            'payout_currency' => 'USD',
            'allowed_geos' => ['US', 'CA'],
            'compliance_risk' => ComplianceRisk::Restricted,
            'compliance_notes' => 'Testosterone / ED category strictly monitored by Google.',
            'network_approval_required' => true,
            'payouts_by_platform' => [
                ['platform' => 'clickbank', 'payout' => 90.00, 'currency' => 'USD'],
                ['platform' => 'buygoods', 'payout' => 95.50, 'currency' => 'USD'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Show')
                ->has('product')
                ->where('product.name', 'Testosterone Prime Booster')
                ->where('product.allowed_geos', ['US', 'CA'])
                ->where('product.compliance_risk', 'restricted')
                ->where('product.compliance_risk_label', 'Restricted')
                ->where('product.compliance_notes', 'Testosterone / ED category strictly monitored by Google.')
                ->where('product.network_approval_required', true)
                ->where('product.payouts_by_platform', [
                    ['platform' => 'clickbank', 'payout' => 90, 'currency' => 'USD'],
                    ['platform' => 'buygoods', 'payout' => 95.50, 'currency' => 'USD'],
                ])
                ->has('options.compliance_risks')
            );
    }

    public function test_index_page_receives_the_new_data(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'Restricted Health Pill',
            'allowed_geos' => ['US', 'CA', 'AU', 'NZ'],
            'compliance_risk' => ComplianceRisk::Restricted,
            'compliance_notes' => 'Limited policy test',
            'network_approval_required' => true,
            'payouts_by_platform' => [
                ['platform' => 'clickbank', 'payout' => 48.50, 'currency' => 'USD'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Restricted Health Pill')
                ->where('products.0.allowed_geos', ['US', 'CA', 'AU', 'NZ'])
                ->where('products.0.compliance_risk', 'restricted')
                ->where('products.0.compliance_risk_label', 'Restricted')
                ->where('products.0.network_approval_required', true)
                ->where('products.0.payouts_by_platform', [
                    ['platform' => 'clickbank', 'payout' => 48.50, 'currency' => 'USD'],
                ])
                ->has('options.compliance_risks')
            );
    }
}
