<?php

namespace Tests\Feature\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\ConversionAction;
use App\Models\GoogleAdsAccount;
use App\Models\Track;
use App\Models\User;
use App\Models\WatchedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchedProductTrackLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleAdsAccount $account;

    private ConversionAction $conversionAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'media_buyer',
        ]);

        $this->account = GoogleAdsAccount::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Scale Account Alpha',
            'customer_id' => '1234567890',
            'refresh_token' => 'mock-refresh-token',
            'status' => 'ACTIVE',
            'is_default' => true,
        ]);

        $this->conversionAction = $this->account->conversionActions()->create([
            'resource_name' => 'customers/1234567890/conversionActions/501',
            'external_id' => '501',
            'name' => 'Affiliate Purchase',
        ]);
    }

    public function test_track_belongs_to_watched_product_and_inverse_relationship(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'ProstaBiome',
            'status' => WatchedProductStatus::Test,
            'payout_amount' => 120.00,
            'payout_currency' => 'USD',
            'payout_cad' => 162.00,
            'platform' => 'clickbank',
        ]);

        $track = Track::query()->create([
            'product_name' => 'ProstaBiome Scale Lander',
            'watched_product_id' => $product->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=buyer&vendor=prostab',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'language' => 'en',
            'typical_commission_cad' => 162.00,
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $this->assertInstanceOf(WatchedProduct::class, $track->watchedProduct);
        $this->assertSame($product->id, $track->watchedProduct->id);
        $this->assertSame('ProstaBiome', $track->watchedProduct->name);

        $this->assertTrue($product->tracks->contains('id', $track->id));
        $this->assertSame(1, $product->tracks()->count());
    }

    public function test_track_create_form_receives_watched_products_and_selected_id(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'Denticore Pro',
            'status' => WatchedProductStatus::Test,
            'platform' => 'clickbank',
            'payout_cad' => 150.00,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/tracks/create?watched_product_id='.$product->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Tracks/Form')
            ->where('selected_watched_product_id', $product->id)
            ->has('watched_products', fn (Assert $products) => $products
                ->where('0.id', $product->id)
                ->where('0.name', 'Denticore Pro')
                ->where('0.status', 'test')
                ->etc()
            )
        );
    }

    public function test_creating_track_with_watched_product_id_persists_link(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'NeuroPure',
            'status' => WatchedProductStatus::Watch,
            'platform' => 'clickbank',
            'payout_cad' => 140.00,
        ]);

        $response = $this->actingAs($this->user)->post('/tracks', [
            'product_name' => 'NeuroPure Cold Traffic',
            'watched_product_id' => $product->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=buyer&vendor=neuropure',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'language' => 'en',
            'typical_commission_cad' => 140.00,
            'google_ads_account_id' => $this->account->id,
            'conversion_mode' => 'existing',
            'conversion_action_id' => $this->conversionAction->id,
            'campaign_ids' => ['12345'],
        ]);

        $response->assertRedirect();
        $track = Track::query()->where('product_name', 'NeuroPure Cold Traffic')->firstOrFail();

        $this->assertSame($product->id, $track->watched_product_id);
        $this->assertDatabaseHas('tracks', [
            'id' => $track->id,
            'watched_product_id' => $product->id,
            'product_name' => 'NeuroPure Cold Traffic',
        ]);
    }

    public function test_creating_track_without_watched_product_id_leaves_field_null(): void
    {
        $response = $this->actingAs($this->user)->post('/tracks', [
            'product_name' => 'Independent Track',
            'watched_product_id' => null,
            'platform' => 'digistore24',
            'affiliate_url' => 'https://www.digistore24.com/redir/12345/buyer/',
            'page_type' => 'presell',
            'strategy' => 'manual_cpc',
            'google_ads_account_id' => $this->account->id,
            'conversion_mode' => 'existing',
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $response->assertRedirect();
        $track = Track::query()->where('product_name', 'Independent Track')->firstOrFail();

        $this->assertNull($track->watched_product_id);
    }

    public function test_updating_track_watched_product_link(): void
    {
        $productA = WatchedProduct::query()->create(['name' => 'Alpha Boost', 'status' => WatchedProductStatus::Watch]);
        $productB = WatchedProduct::query()->create(['name' => 'Beta Boost', 'status' => WatchedProductStatus::Test]);

        $track = Track::query()->create([
            'product_name' => 'Boost Offer',
            'watched_product_id' => $productA->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        // Switch link to product B
        $response = $this->actingAs($this->user)->put('/tracks/'.$track->id, [
            'product_name' => 'Boost Offer',
            'watched_product_id' => $productB->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_mode' => 'existing',
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $response->assertRedirect('/tracks/'.$track->id);
        $this->assertSame($productB->id, $track->fresh()->watched_product_id);

        // Unlink to null
        $this->actingAs($this->user)->put('/tracks/'.$track->id, [
            'product_name' => 'Boost Offer',
            'watched_product_id' => null,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_mode' => 'existing',
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $this->assertNull($track->fresh()->watched_product_id);
    }

    public function test_watching_products_index_exposes_tracks_count_and_testing_status(): void
    {
        $testedProduct = WatchedProduct::query()->create([
            'name' => 'Cortexi Drops',
            'status' => WatchedProductStatus::Test,
            'payout_cad' => 155.00,
        ]);

        $unlinkedProduct = WatchedProduct::query()->create([
            'name' => 'Quietum Plus',
            'status' => WatchedProductStatus::Watch,
            'payout_cad' => 120.00,
        ]);

        Track::query()->create([
            'product_name' => 'Cortexi US Search',
            'watched_product_id' => $testedProduct->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=buyer&vendor=cortexi',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $response = $this->actingAs($this->user)->get('/watching-products');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('WatchingProducts/Index')
            ->has('products', 2)
            ->where('products', function ($products) use ($testedProduct, $unlinkedProduct) {
                $tested = collect($products)->firstWhere('id', $testedProduct->id);
                $unlinked = collect($products)->firstWhere('id', $unlinkedProduct->id);

                return $tested !== null
                    && $tested['tracks_count'] === 1
                    && count($tested['tracks']) === 1
                    && $tested['tracks'][0]['name'] === 'Cortexi US Search'
                    && $unlinked !== null
                    && $unlinked['tracks_count'] === 0
                    && count($unlinked['tracks']) === 0;
            })
        );
    }

    public function test_watching_product_show_eager_loads_linked_tracks(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'Sugar Defender',
            'status' => WatchedProductStatus::Test,
            'payout_cad' => 160.00,
        ]);

        $track = Track::query()->create([
            'product_name' => 'Sugar Defender TSL Funnel',
            'watched_product_id' => $product->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=buyer&vendor=sugardef',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $response = $this->actingAs($this->user)->get('/watching-products/'.$product->id);
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('WatchingProducts/Show')
            ->where('product.id', $product->id)
            ->where('product.tracks_count', 1)
            ->has('product.tracks', 1)
            ->where('product.tracks.0.id', $track->id)
            ->where('product.tracks.0.name', 'Sugar Defender TSL Funnel')
            ->where('product.tracks.0.account_name', 'Scale Account Alpha')
        );
    }

    public function test_deleting_watched_product_nulls_track_foreign_key(): void
    {
        $product = WatchedProduct::query()->create([
            'name' => 'Temporary Product',
            'status' => WatchedProductStatus::Rejected,
        ]);

        $track = Track::query()->create([
            'product_name' => 'Preserved Track',
            'watched_product_id' => $product->id,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/aff',
            'page_type' => 'review_robust',
            'strategy' => 'target_cpa',
            'google_ads_account_id' => $this->account->id,
            'conversion_action_id' => $this->conversionAction->id,
        ]);

        $this->assertSame($product->id, $track->watched_product_id);

        $product->delete();

        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
        $this->assertNull($track->fresh()->watched_product_id);
    }
}
