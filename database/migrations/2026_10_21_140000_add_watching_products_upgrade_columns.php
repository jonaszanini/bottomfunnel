<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            // Payout
            $table->decimal('payout_amount', 12, 2)->nullable()->after('platforms');
            $table->char('payout_currency', 3)->default('USD')->after('payout_amount');
            $table->string('payout_model', 32)->nullable()->after('payout_currency');

            // Listing
            $table->decimal('gravity', 8, 2)->nullable()->after('payout_notes');
            $table->string('platform', 64)->nullable()->after('gravity');
            $table->text('listing_url')->nullable()->after('platform');
            $table->string('listing_status', 32)->default('unknown')->after('listing_url');
            $table->decimal('cancel_rate', 5, 2)->nullable()->after('listing_status');

            // Funnel and category
            $table->json('funnel_flags')->nullable()->after('page_type');
            $table->string('product_kind', 32)->default('nutra')->after('funnel_flags');
            $table->boolean('esteira')->default(false)->after('product_kind');
            $table->boolean('restricted_category')->default(false)->after('esteira');
            $table->string('strategy', 64)->nullable()->after('restricted_category');
            $table->string('strategy_source', 32)->default('auto')->after('strategy');

            // BoF test
            $table->boolean('bof_translation')->default(false)->after('true_bof');
            $table->boolean('bof_vpn_serp')->default(false)->after('bof_translation');
            $table->integer('bof_image_match_pct')->default(0)->after('bof_vpn_serp');
            $table->string('bof_qualifier', 255)->nullable()->after('bof_image_match_pct');
            $table->boolean('bof_homonym')->default(false)->after('bof_qualifier');

            // Demand
            $table->decimal('trends_7d', 8, 2)->nullable()->after('demand_notes');
            $table->decimal('trends_30d', 8, 2)->nullable()->after('trends_7d');
            $table->decimal('trends_90d', 8, 2)->nullable()->after('trends_30d');
            $table->string('trends_trend', 32)->nullable()->after('trends_90d');
            $table->string('flowspy_traffic_trend', 32)->nullable()->after('trends_trend');

            // Keyword Planner
            $table->integer('kp_volume')->nullable()->after('flowspy_traffic_trend');
            $table->decimal('kp_yoy', 8, 2)->nullable()->after('kp_volume');
            $table->decimal('kp_3m', 8, 2)->nullable()->after('kp_yoy');
            $table->decimal('kp_cpc_amount', 12, 2)->nullable()->after('kp_3m');
            $table->char('kp_cpc_currency', 3)->default('USD')->nullable()->after('kp_cpc_amount');

            // Competition
            $table->decimal('brand_cpc_amount', 12, 2)->nullable()->after('kp_cpc_currency');
            $table->char('brand_cpc_currency', 3)->default('USD')->nullable()->after('brand_cpc_amount');
            $table->decimal('est_cvr', 6, 4)->default(0.0200)->after('brand_cpc_currency');
            $table->integer('advertiser_count')->default(0)->after('est_cvr');
            $table->boolean('vendor_bids_brand')->default(false)->after('advertiser_count');

            // Campaign tracking
            $table->decimal('spend_amount', 12, 2)->default(0.00)->after('vendor_bids_brand');
            $table->char('spend_currency', 3)->default('USD')->after('spend_amount');
            $table->integer('sales')->default(0)->after('spend_currency');
            $table->integer('checkouts')->default(0)->after('sales');
            $table->decimal('commission_earned', 12, 2)->default(0.00)->after('checkouts');

            // Scoring and refresh
            $table->integer('score')->nullable()->after('commission_earned');
            $table->json('score_breakdown')->nullable()->after('score');
            $table->json('score_flags')->nullable()->after('score_breakdown');
            $table->integer('rank')->nullable()->after('score_flags');
            $table->timestamp('scored_at')->nullable()->after('rank');
            $table->timestamp('last_refreshed_at')->nullable()->after('scored_at');
            $table->integer('refresh_priority')->default(0)->after('last_refreshed_at');

            // Indexes
            $table->index('score');
            $table->index('rank');
            $table->index('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $table->dropIndex(['score']);
            $table->dropIndex(['rank']);
            $table->dropIndex(['last_refreshed_at']);

            $table->dropColumn([
                'payout_amount',
                'payout_currency',
                'payout_model',
                'gravity',
                'platform',
                'listing_url',
                'listing_status',
                'cancel_rate',
                'funnel_flags',
                'product_kind',
                'esteira',
                'restricted_category',
                'strategy',
                'strategy_source',
                'bof_translation',
                'bof_vpn_serp',
                'bof_image_match_pct',
                'bof_qualifier',
                'bof_homonym',
                'trends_7d',
                'trends_30d',
                'trends_90d',
                'trends_trend',
                'flowspy_traffic_trend',
                'kp_volume',
                'kp_yoy',
                'kp_3m',
                'kp_cpc_amount',
                'kp_cpc_currency',
                'brand_cpc_amount',
                'brand_cpc_currency',
                'est_cvr',
                'advertiser_count',
                'vendor_bids_brand',
                'spend_amount',
                'spend_currency',
                'sales',
                'checkouts',
                'commission_earned',
                'score',
                'score_breakdown',
                'score_flags',
                'rank',
                'scored_at',
                'last_refreshed_at',
                'refresh_priority',
            ]);
        });
    }
};
