<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            if (! Schema::hasColumn('watched_products', 'strategy_phase2')) {
                $table->string('strategy_phase2', 64)->nullable()->after('strategy');
            }
            if (! Schema::hasColumn('watched_products', 'strategy_switch_trigger')) {
                $table->text('strategy_switch_trigger')->nullable()->after('strategy_phase2');
            }
            if (! Schema::hasColumn('watched_products', 'recheck_at')) {
                $table->date('recheck_at')->nullable()->index()->after('last_reviewed_at');
            }
            if (! Schema::hasColumn('watched_products', 'promotion_criteria')) {
                $table->text('promotion_criteria')->nullable()->after('strategy_switch_trigger');
            }
            if (! Schema::hasColumn('watched_products', 'approval_status')) {
                $table->string('approval_status', 32)->default('unknown')->after('approval_required');
            }
            if (! Schema::hasColumn('watched_products', 'network_approval_status')) {
                $table->string('network_approval_status', 32)->default('unknown')->after('network_approval_required');
            }
            if (Schema::hasColumn('watched_products', 'compliance_risk')) {
                $table->string('compliance_risk', 32)->default('not_assessed')->change();
            }
        });

        // Backfill tri-state approval from legacy boolean flags
        // Documented: old false maps to 'unknown' because the boolean checkbox could not express 'explicitly not required'
        if (Schema::hasColumn('watched_products', 'approval_required') && Schema::hasColumn('watched_products', 'approval_status')) {
            DB::table('watched_products')
                ->where('approval_required', true)
                ->update(['approval_status' => 'yes']);

            DB::table('watched_products')
                ->where('approval_required', false)
                ->orWhereNull('approval_required')
                ->update(['approval_status' => 'unknown']);
        }

        if (Schema::hasColumn('watched_products', 'network_approval_required') && Schema::hasColumn('watched_products', 'network_approval_status')) {
            DB::table('watched_products')
                ->where('network_approval_required', true)
                ->update(['network_approval_status' => 'yes']);

            DB::table('watched_products')
                ->where('network_approval_required', false)
                ->orWhereNull('network_approval_required')
                ->update(['network_approval_status' => 'unknown']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watched_products', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'strategy_phase2',
                'strategy_switch_trigger',
                'recheck_at',
                'promotion_criteria',
                'approval_status',
                'network_approval_status',
            ] as $col) {
                if (Schema::hasColumn('watched_products', $col)) {
                    $drop[] = $col;
                }
            }

            if (! empty($drop)) {
                $table->dropColumn($drop);
            }

            if (Schema::hasColumn('watched_products', 'compliance_risk')) {
                $table->string('compliance_risk', 32)->default('low')->change();
            }
        });
    }
};
