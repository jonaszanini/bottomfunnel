<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\ApiUsage;
use App\Models\CampaignAlert;
use App\Models\Conversion;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use App\Models\IpBlock;
use App\Models\Page;
use App\Models\ProductNegativeKeyword;
use App\Models\ProductScoreHistory;
use App\Models\Track;
use App\Models\Visit;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\RankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaAndFactoryCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_model_factories_create_valid_records(): void
    {
        $account = GoogleAdsAccount::factory()->create();
        $this->assertDatabaseHas('google_ads_accounts', ['id' => $account->id]);

        $campaign = GoogleAdsCampaign::factory()->create(['google_ads_account_id' => $account->id]);
        $this->assertDatabaseHas('google_ads_campaigns', ['id' => $campaign->id]);

        $watchedProduct = WatchedProduct::factory()->create();
        $this->assertDatabaseHas('watched_products', ['id' => $watchedProduct->id]);

        $track = Track::factory()->create([
            'google_ads_account_id' => $account->id,
            'watched_product_id' => $watchedProduct->id,
        ]);
        $this->assertDatabaseHas('tracks', ['id' => $track->id, 'watched_product_id' => $watchedProduct->id]);

        $page = Page::factory()->create(['track_id' => $track->id]);
        $this->assertDatabaseHas('pages', ['id' => $page->id]);

        $visit = Visit::factory()->create(['track_id' => $track->id, 'page_id' => $page->id]);
        $this->assertDatabaseHas('visits', ['id' => $visit->id]);

        $conversion = Conversion::factory()->create(['track_id' => $track->id, 'visit_id' => $visit->id]);
        $this->assertDatabaseHas('conversions', ['id' => $conversion->id]);

        $ipBlock = IpBlock::factory()->create(['track_id' => $track->id, 'google_ads_account_id' => $account->id]);
        $this->assertDatabaseHas('ip_blocks', ['id' => $ipBlock->id]);

        $adSpend = AdSpend::factory()->create(['track_id' => $track->id]);
        $this->assertDatabaseHas('ad_spend', ['id' => $adSpend->id]);

        $apiUsage = ApiUsage::factory()->create();
        $this->assertDatabaseHas('api_usage', ['id' => $apiUsage->id]);

        $alert = CampaignAlert::factory()->create([
            'track_id' => $track->id,
            'product_id' => $watchedProduct->id,
            'account_id' => $account->id,
        ]);
        $this->assertDatabaseHas('campaign_alerts', ['id' => $alert->id]);

        $history = ProductScoreHistory::factory()->create(['product_id' => $watchedProduct->id]);
        $this->assertDatabaseHas('product_score_history', ['id' => $history->id]);

        $negative = ProductNegativeKeyword::factory()->create(['product_id' => $watchedProduct->id]);
        $this->assertDatabaseHas('product_negative_keywords', ['id' => $negative->id]);
    }

    public function test_all_model_fillable_columns_exist_in_database_schema(): void
    {
        $modelClasses = [
            Page::class => 'pages',
            WatchedProduct::class => 'watched_products',
            AdSpend::class => 'ad_spend',
            ApiUsage::class => 'api_usage',
            CampaignAlert::class => 'campaign_alerts',
            Conversion::class => 'conversions',
            GoogleAdsCampaign::class => 'google_ads_campaigns',
            Visit::class => 'visits',
            IpBlock::class => 'ip_blocks',
            Track::class => 'tracks',
            GoogleAdsAccount::class => 'google_ads_accounts',
            ProductScoreHistory::class => 'product_score_history',
            ProductNegativeKeyword::class => 'product_negative_keywords',
        ];

        foreach ($modelClasses as $class => $table) {
            $model = new $class;
            $fillables = $model->getFillable();
            $tableColumns = Schema::getColumnListing($table);

            foreach ($fillables as $column) {
                $this->assertContains(
                    $column,
                    $tableColumns,
                    "Column '{$column}' listed in {$class}::\$fillable does not exist in table '{$table}'."
                );
            }
        }
    }

    public function test_rank_service_recalculation_runs_cleanly_on_sqlite(): void
    {
        WatchedProduct::factory()->create([
            'name' => 'High Score Product',
            'score' => 95,
            'rank' => null,
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Medium Score Product',
            'score' => 70,
            'rank' => null,
        ]);

        WatchedProduct::factory()->create([
            'name' => 'Low Score Product',
            'score' => 40,
            'rank' => null,
        ]);

        $rankService = app(RankService::class);
        $rankService->recalculateRanks();

        $this->assertDatabaseHas('watched_products', ['name' => 'High Score Product', 'rank' => 1]);
        $this->assertDatabaseHas('watched_products', ['name' => 'Medium Score Product', 'rank' => 2]);
        $this->assertDatabaseHas('watched_products', ['name' => 'Low Score Product', 'rank' => 3]);
    }
}
