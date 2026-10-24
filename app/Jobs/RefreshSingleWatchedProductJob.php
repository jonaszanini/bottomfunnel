<?php

namespace App\Jobs;

use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshSingleWatchedProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public int $productId,
        public bool $forceFresh = true,
    ) {}

    public function handle(
        SerpApiClient $serpClient,
        BofChecker $bofChecker,
        NegativeKeywordGenerator $negativeGenerator,
        PotentialScoreService $scoreService,
        RankService $rankService,
        QuotaBudget $budget
    ): void {
        $product = WatchedProduct::query()->find($this->productId);
        if (! $product) {
            Log::warning("RefreshSingleWatchedProductJob: WatchedProduct {$this->productId} not found.");

            return;
        }

        $lock = Cache::lock("refresh_product_job_lock:{$this->productId}", 30);
        if (! $lock->get()) {
            Log::info("RefreshSingleWatchedProductJob: Product {$this->productId} is already being refreshed by another worker. Skipping.");

            return;
        }

        if (! $budget->canSpendManual(2)) {
            $lock->release();
            Log::warning("RefreshSingleWatchedProductJob: SerpApi monthly quota reached for product {$this->productId}.");

            return;
        }

        try {
            $data = $serpClient->fetchProductData($product, forceFresh: $this->forceFresh);

            if ($data['success']) {
                if (! empty($data['trends'])) {
                    $product->trends_7d = $data['trends']['trends_7d'] ?? $product->trends_7d;
                    $product->trends_30d = $data['trends']['trends_30d'] ?? $product->trends_30d;
                    $product->trends_90d = $data['trends']['trends_90d'] ?? $product->trends_90d;
                    if (! empty($data['trends']['trends_trend'])) {
                        $product->trends_trend = $data['trends']['trends_trend'];
                    }
                }

                if (! empty($data['serp'])) {
                    $product->advertiser_count = $data['serp']['advertiser_count'] ?? $product->advertiser_count;
                }

                if (! empty($data['bof'])) {
                    $product->bof_vpn_serp = $data['bof']['bof_vpn_serp'];
                    $product->bof_image_match_pct = $data['bof']['bof_image_match_pct'];
                    if ($data['bof']['bof_translation']) {
                        $product->bof_translation = true;
                    }
                }
            } else {
                if (! $product->bof_translation && $bofChecker->detectTranslationPass($product->name)) {
                    $product->bof_translation = true;
                }
            }

            // Light pass: sync base seeds, qualifier rules, and generic meanings (no noisy SERP negatives)
            $negativeGenerator->generateBase($product);

            $product->last_refreshed_at = now();
            $product->save();

            // Rescore and record score history
            $scoreResult = $scoreService->score($product);
            $product->update([
                'score' => $scoreResult['score'],
                'score_breakdown' => $scoreResult['breakdown'],
                'score_flags' => $scoreResult['flags'],
                'scored_at' => now(),
            ]);

            ProductScoreHistory::query()->create([
                'product_id' => $product->id,
                'score' => $scoreResult['score'],
                'breakdown' => $scoreResult['breakdown'],
                'flags' => $scoreResult['flags'],
                'inputs_snapshot' => [
                    'name' => $product->name,
                    'payout_amount' => $product->payout_amount,
                    'payout_currency' => $product->payout_currency,
                    'page_type' => $product->page_type,
                    'strategy' => $product->strategy?->value,
                    'bof_translation' => (bool) $product->bof_translation,
                    'bof_vpn_serp' => (bool) $product->bof_vpn_serp,
                    'bof_image_match_pct' => (int) ($product->bof_image_match_pct ?? 0),
                ],
                'created_at' => now(),
            ]);

            $rankService->recalculateRanks();
        } catch (Throwable $e) {
            Log::error("RefreshSingleWatchedProductJob failed for product {$this->productId}: {$e->getMessage()}", [
                'product_id' => $this->productId,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
