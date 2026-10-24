<?php

namespace App\Services\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;
use Illuminate\Support\Facades\DB;

class RankService
{
    /**
     * Recalculate rank for all active/non-archived products.
     * Order by score DESC, demand DESC, payout DESC.
     * Executes rank updates as a single batched CASE WHEN SQL statement.
     */
    public function recalculateRanks(): void
    {
        $products = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->get(['id', 'score', 'trends_30d', 'kp_volume', 'payout_amount', 'payout_cad', 'rank']);

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

        $updates = [];
        foreach ($sorted as $index => $product) {
            $newRank = $index + 1;
            if ($product->rank !== $newRank) {
                $updates[$product->id] = $newRank;
            }
        }

        if (! empty($updates)) {
            // Batched CASE WHEN update in chunks of 100 to avoid SQL parameter limits
            $ids = array_keys($updates);
            foreach (array_chunk($ids, 100) as $chunkIds) {
                $cases = [];
                $params = [];
                foreach ($chunkIds as $id) {
                    $cases[] = 'WHEN id = ? THEN ?';
                    $params[] = $id;
                    $params[] = $updates[$id];
                }

                $casesSql = implode(' ', $cases);
                $inPlaceholders = implode(',', array_fill(0, count($chunkIds), '?'));
                $params = array_merge($params, $chunkIds);

                DB::statement("UPDATE watched_products SET rank = CASE {$casesSql} END WHERE id IN ({$inPlaceholders})", $params);
            }
        }

        // Set rank to null for rejected products in a single bulk query
        WatchedProduct::query()
            ->where('status', WatchedProductStatus::Rejected)
            ->whereNotNull('rank')
            ->update(['rank' => null]);
    }
}
