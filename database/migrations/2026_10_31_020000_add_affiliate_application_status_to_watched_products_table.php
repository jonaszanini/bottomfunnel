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
            if (! Schema::hasColumn('watched_products', 'affiliate_application_status')) {
                $table->string('affiliate_application_status', 20)->default('not_requested')->index()->after('network_approval_status');
            }
            if (! Schema::hasColumn('watched_products', 'affiliate_application_requested_at')) {
                $table->date('affiliate_application_requested_at')->nullable()->after('affiliate_application_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('watched_products', 'affiliate_application_status')) {
                $drop[] = 'affiliate_application_status';
            }
            if (Schema::hasColumn('watched_products', 'affiliate_application_requested_at')) {
                $drop[] = 'affiliate_application_requested_at';
            }

            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
