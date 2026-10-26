<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $table->json('search_volume_by_geo')->nullable()->after('payouts_by_platform');
            $table->unsignedInteger('search_volume_total')->nullable()->index()->after('search_volume_by_geo');
            $table->boolean('search_volume_is_range')->default(false)->after('search_volume_total');
            $table->string('search_volume_keyword', 191)->nullable()->after('search_volume_is_range');
            $table->timestamp('search_volume_checked_at')->nullable()->after('search_volume_keyword');
            $table->string('search_volume_error', 255)->nullable()->after('search_volume_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $table->dropIndex(['search_volume_total']);
            $table->dropColumn([
                'search_volume_by_geo',
                'search_volume_total',
                'search_volume_is_range',
                'search_volume_keyword',
                'search_volume_checked_at',
                'search_volume_error',
            ]);
        });
    }
};
