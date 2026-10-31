<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('watched_products', 'network_offer_url')) {
            Schema::table('watched_products', function (Blueprint $table) {
                $table->string('network_offer_url', 2048)->nullable()->after('affiliate_link');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('watched_products', 'network_offer_url')) {
            Schema::table('watched_products', function (Blueprint $table) {
                $table->dropColumn('network_offer_url');
            });
        }
    }
};
