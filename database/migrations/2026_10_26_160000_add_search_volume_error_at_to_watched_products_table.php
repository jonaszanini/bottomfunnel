<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('watched_products', 'search_volume_error_at')) {
            Schema::table('watched_products', function (Blueprint $table) {
                $table->timestamp('search_volume_error_at')->nullable()->after('search_volume_error');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('watched_products', 'search_volume_error_at')) {
            Schema::table('watched_products', function (Blueprint $table) {
                $table->dropColumn('search_volume_error_at');
            });
        }
    }
};
