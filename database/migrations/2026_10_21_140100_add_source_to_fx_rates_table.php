<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fx_rates') && ! Schema::hasColumn('fx_rates', 'source')) {
            Schema::table('fx_rates', function (Blueprint $table) {
                $table->string('source', 64)->nullable()->after('cad_per_unit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fx_rates') && Schema::hasColumn('fx_rates', 'source')) {
            Schema::table('fx_rates', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
