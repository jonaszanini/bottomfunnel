<?php

namespace Tests\Feature\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\QuotaBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WatchingProductsQuotaDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'quota_tester',
        ]);

        Cache::forget('dataforseo_user_data');
    }

    public function test_watching_products_index_shows_dataforseo_balance_when_provider_is_dataforseo(): void
    {
        Config::set('watching.seo.provider', 'dataforseo');
        Config::set('watching.dataforseo.login', 'dfs_login_user');
        Config::set('watching.dataforseo.password', 'super_secret_dfs_pwd_987');

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20230623',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'login' => 'dfs_login_user',
                                'money' => [
                                    'total' => 100.0,
                                    'balance' => 12.45,
                                ],
                                'statistics' => [
                                    'day' => ['cost' => 0.75],
                                ],
                                'limits' => [
                                    'day' => ['limit' => 1000],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Test,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->where('seo_provider', 'dataforseo')
                ->where('dataforseo_balance', 12.45)
                ->where('metrics.seo_provider', 'dataforseo')
                ->where('metrics.dataforseo_balance', 12.45)
                ->has('dataforseo_usage_summary')
                ->missing('metrics.serpapi_limit')
                ->missing('metrics.serpapi_used')
            );

        // Assert the old "/ 250" text and "SerpApi Quota (Month)" are not rendered
        $response->assertDontSee('/ 250');
        $response->assertDontSee('SerpApi Quota (Month)');

        // Assert API credentials are NOT exposed to frontend
        $response->assertDontSee('super_secret_dfs_pwd_987');
    }

    public function test_watching_products_index_restores_serpapi_quota_when_provider_is_serpapi(): void
    {
        Config::set('watching.seo.provider', 'serpapi');

        $budget = app(QuotaBudget::class);
        ApiUsage::query()->create([
            'provider' => 'serpapi',
            'period' => $budget->currentPeriod(),
            'calls' => 4,
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Test,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/watching-products');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Index')
                ->where('seo_provider', 'serpapi')
                ->where('metrics.seo_provider', 'serpapi')
                ->where('metrics.serpapi_used', 4)
                ->where('metrics.serpapi_cap', 230)
                ->where('metrics.serpapi_limit', 250)
            );

        // Assert the old SerpApi quota display is restored in props
        $response->assertSee('"serpapi_limit":250', false);
        $response->assertSee('"serpapi_used":4', false);
    }

    public function test_watching_products_show_receives_dataforseo_balance_when_provider_is_dataforseo(): void
    {
        Config::set('watching.seo.provider', 'dataforseo');
        Config::set('watching.dataforseo.login', 'dfs_login_user');
        Config::set('watching.dataforseo.password', 'super_secret_dfs_pwd_987');

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20230623',
                'status_code' => 20000,
                'tasks' => [
                    [
                        'result' => [
                            [
                                'money' => [
                                    'total' => 100.0,
                                    'balance' => 12.45,
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $product = WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Test,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Show')
                ->where('seo_provider', 'dataforseo')
                ->where('dataforseo_balance', 12.45)
                ->has('dataforseo_usage_summary')
            );

        // Assert API credentials are NOT exposed
        $response->assertDontSee('super_secret_dfs_pwd_987');
    }

    public function test_watching_products_show_restores_serpapi_when_provider_is_serpapi(): void
    {
        Config::set('watching.seo.provider', 'serpapi');

        $product = WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Test,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/watching-products/{$product->id}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WatchingProducts/Show')
                ->where('seo_provider', 'serpapi')
                ->where('serpapi_usage.provider', 'serpapi')
                ->has('serpapi_usage.used_this_month')
                ->has('serpapi_usage.total_limit')
            );
    }
}
