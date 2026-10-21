<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('watched_products') || ! Schema::hasColumn('watched_products', 'payout_amount')) {
            return;
        }

        // Default CAD per USD conversion rate if no rate is cached yet
        $usdToCadRate = 1.38000000;

        if (Schema::hasTable('fx_rates')) {
            $latest = DB::table('fx_rates')
                ->where('currency', 'USD')
                ->orderByDesc('rate_date')
                ->first();

            if ($latest && (float) $latest->cad_per_unit > 0) {
                $usdToCadRate = (float) $latest->cad_per_unit;
            }
        }

        $products = DB::table('watched_products')->whereNotNull('payout_cad')->get();

        foreach ($products as $product) {
            $usdAmount = (float) $product->payout_cad;
            $recomputedCad = round($usdAmount * $usdToCadRate, 2);

            DB::table('watched_products')
                ->where('id', $product->id)
                ->update([
                    'payout_amount' => $usdAmount,
                    'payout_currency' => 'USD',
                    'payout_cad' => $recomputedCad,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('watched_products') || ! Schema::hasColumn('watched_products', 'payout_amount')) {
            return;
        }

        $products = DB::table('watched_products')->whereNotNull('payout_amount')->get();

        foreach ($products as $product) {
            DB::table('watched_products')
                ->where('id', $product->id)
                ->update([
                    'payout_cad' => $product->payout_amount,
                    'payout_amount' => null,
                ]);
        }
    }
};
