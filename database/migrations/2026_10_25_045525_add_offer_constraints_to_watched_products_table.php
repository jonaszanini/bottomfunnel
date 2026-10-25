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
            $table->json('allowed_geos')->nullable()->after('geos');
            $table->string('compliance_risk', 32)->default('low')->after('restricted_category');
            $table->text('compliance_notes')->nullable()->after('compliance_risk');
            $table->boolean('network_approval_required')->default(false)->after('approval_required');
            $table->json('payouts_by_platform')->nullable()->after('payout_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $table->dropColumn([
                'allowed_geos',
                'compliance_risk',
                'compliance_notes',
                'network_approval_required',
                'payouts_by_platform',
            ]);
        });
    }
};
