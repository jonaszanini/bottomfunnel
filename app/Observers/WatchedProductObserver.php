<?php

namespace App\Observers;

use App\Enums\StrategySource;
use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\StrategyRecommender;

class WatchedProductObserver
{
    public function __construct(
        protected PotentialScoreService $scoreService = new PotentialScoreService,
        protected StrategyRecommender $strategyRecommender = new StrategyRecommender,
        protected NegativeKeywordGenerator $negativeGenerator = new NegativeKeywordGenerator,
        protected RankService $rankService = new RankService,
        protected FrankfurterFx $fx = new FrankfurterFx
    ) {}

    /**
     * Handle the WatchedProduct "created" event.
     */
    public function created(WatchedProduct $product): void
    {
        // Compute payout_cad cache if payout_amount is present but payout_cad is not
        if ($product->payout_amount !== null && $product->payout_cad === null) {
            $cad = $this->fx->toCad((float) $product->payout_amount, $product->payout_currency ?? 'USD');
            $product->payout_cad = $cad;
        }

        // Recommend strategy if still auto
        if (empty($product->strategy) || $product->strategy_source === StrategySource::Auto) {
            $recommendation = $this->strategyRecommender->recommend($product);
            $product->strategy = $recommendation['strategy'];
            $product->strategy_source = StrategySource::Auto;
        }

        // Calculate score
        $scoreResult = $this->scoreService->score($product);
        $product->score = $scoreResult['score'];
        $product->score_breakdown = $scoreResult['breakdown'];
        $product->score_flags = $scoreResult['flags'];
        $product->scored_at = now();

        $product->saveQuietly();

        // Write score history
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
            ],
            'created_at' => now(),
        ]);

        // Copy seeds and generate negative keywords
        $this->negativeGenerator->generateForProduct($product, [], true);

        // Recalculate ranks across watchlist
        $this->rankService->recalculateRanks();
    }

    /**
     * Handle the WatchedProduct "updated" event.
     */
    public function updated(WatchedProduct $product): void
    {
        // When strategy changes, recompute enabled flags on negative keywords
        if ($product->wasChanged('strategy') && $product->strategy) {
            $this->negativeGenerator->syncStrategyRules($product, $product->strategy);
        }

        // When relevant scoring attributes change, recalculate rank
        if ($product->wasChanged(['score', 'trends_30d', 'kp_volume', 'payout_amount', 'status'])) {
            $this->rankService->recalculateRanks();
        }
    }
}
