<?php

namespace App\Observers;

use App\Enums\StrategySource;
use App\Enums\WatchedProductStatus;
use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
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
        protected FrankfurterFx $fx = new FrankfurterFx,
        protected BofChecker $bofChecker = new BofChecker
    ) {}

    /**
     * Handle the WatchedProduct "created" event.
     */
    public function created(WatchedProduct $product): void
    {
        // Compute payout_cad cache if payout_amount is present but payout_cad is not
        if ($product->payout_amount !== null && $product->payout_cad === null) {
            $cad = Money::make($product->payout_amount, $product->payout_currency ?? 'USD')->toCad();
            $product->payout_cad = $cad;
        }

        // Auto-detect bof_translation if not set and name passes target language checks
        if (! $product->bof_translation && $this->bofChecker->detectTranslationPass($product->name)) {
            $product->bof_translation = true;
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
                'bof_translation' => (bool) $product->bof_translation,
                'bof_vpn_serp' => (bool) $product->bof_vpn_serp,
                'bof_image_match_pct' => (int) ($product->bof_image_match_pct ?? 0),
            ],
            'created_at' => now(),
        ]);

        // Light pass: copy base seeds, qualifier rules, and generic meanings
        $this->negativeGenerator->generateBase($product);
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

        // Auto-generate campaign negatives when promoted to Test status (ready for campaign)
        if ($product->wasChanged('status') && $product->status === WatchedProductStatus::Test) {
            $this->negativeGenerator->generateCampaign($product);
        }

        // When scoring fields change without an explicit score change, trigger rescore
        $scoringFields = [
            'bof_translation',
            'bof_vpn_serp',
            'bof_image_match_pct',
            'bof_qualifier',
            'bof_homonym',
            'trends_trend',
            'trends_7d',
            'trends_30d',
            'trends_90d',
            'flowspy_traffic_trend',
            'payout_amount',
            'payout_currency',
            'payout_model',
            'page_type',
            'brand_bidding',
            'advertiser_count',
            'vendor_bids_brand',
            'kp_volume',
            'cancel_rate',
            'listing_status',
            'restricted_category',
            'approval_required',
        ];

        if ($product->wasChanged($scoringFields) && ! $product->wasChanged('score')) {
            $scoreResult = $this->scoreService->score($product);
            $product->score = $scoreResult['score'];
            $product->score_breakdown = $scoreResult['breakdown'];
            $product->score_flags = $scoreResult['flags'];
            $product->scored_at = now();
            $product->saveQuietly();

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
                    'trends_trend' => $product->trends_trend?->value,
                    'trends_7d' => $product->trends_7d,
                    'trends_30d' => $product->trends_30d,
                    'trends_90d' => $product->trends_90d,
                ],
                'created_at' => now(),
            ]);
        }
    }
}
