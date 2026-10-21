<?php

namespace App\Services\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;

class RankService
{
    /**
     * Recalculate rank for all active/non-archived products.
     * Order by score DESC, demand DESC, payout DESC.
     */
    public function recalculateRanks(): void
    {
        $products = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->get();

        // Sort descending: score, then demand (trends_30d or kp_volume), then payout_amount
        $sorted = $products->sort(function (WatchedProduct $a, WatchedProduct $b) {
            $scoreA = $a->score ?? -1;
            $scoreB = $b->score ?? -1;
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $demandA = (float) ($a->trends_30d ?? $a->kp_volume ?? 0);
            $demandB = (float) ($b->trends_30d ?? $b->kp_volume ?? 0);
            if ($demandA !== $demandB) {
                return $demandB <=> $demandA;
            }

            $payoutA = (float) ($a->payout_amount ?? $a->payout_cad ?? 0);
            $payoutB = (float) ($b->payout_amount ?? $b->payout_cad ?? 0);

            return $payoutB <=> $payoutA;
        })->values();

        foreach ($sorted as $index => $product) {
            $rank = $index + 1;
            if ($product->rank !== $rank) {
                WatchedProduct::query()->where('id', $product->id)->update(['rank' => $rank]);
            }
        }

        // Set rank to null for rejected products
        WatchedProduct::query()
            ->where('status', WatchedProductStatus::Rejected)
            ->whereNotNull('rank')
            ->update(['rank' => null]);
    }
}
