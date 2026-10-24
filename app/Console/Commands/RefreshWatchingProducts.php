<?php

namespace App\Console\Commands;

use App\Enums\ListingStatus;
use App\Enums\StrategySource;
use App\Enums\WatchedProductStatus;
use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use App\Services\Google\CampaignSafetyService;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\ListingChecker;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use App\Services\WatchingProducts\StrategyRecommender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshWatchingProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watching:refresh
                            {--product= : Specific WatchedProduct ID to refresh}
                            {--dry-run : Print budget plan without making HTTP calls}
                            {--no-serp : Skip SerpApi calls}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Weekly automated refresh for Watched Products within SerpApi free-tier budget';

    /**
     * Execute the console command.
     */
    public function handle(
        QuotaBudget $budget,
        FrankfurterFx $fx,
        ListingChecker $listingChecker,
        SerpApiClient $serpClient,
        PotentialScoreService $scoreService,
        StrategyRecommender $strategyRecommender,
        NegativeKeywordGenerator $negativeGenerator,
        RankService $rankService,
        BofChecker $bofChecker,
        CampaignSafetyService $campaignSafety
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        $noSerp = (bool) $this->option('no-serp');
        $productId = $this->option('product');

        $usedCalls = $budget->callsUsedThisMonth();
        $monthlyCap = config('watching.serpapi_monthly_cap', 230);
        $weeklyAllowance = $budget->weeklyCallAllowance();
        $isQuotaHigh = $budget->isQuotaHigh();

        if ($isQuotaHigh) {
            $this->warn("SerpApi monthly quota usage has reached 85%+ ({$usedCalls}/{$monthlyCap} calls). Automatically restricting scheduled refresh to high priority or active campaign products only.");
        }

        if ($productId) {
            $productsToRefresh = WatchedProduct::query()->where('id', $productId)->get();
            if ($productsToRefresh->isEmpty()) {
                $this->error("Product with ID {$productId} not found.");

                return self::FAILURE;
            }
        } else {
            $productsToRefresh = $budget->selectProductsForWeeklyRefresh($isQuotaHigh);
        }

        // DRY-RUN PLAN
        if ($isDryRun) {
            $this->info('=== Watching Products Refresh Budget Plan (Dry Run) ===');
            $this->line("Month: {$budget->currentPeriod()}");
            $this->line("SerpApi Calls Used This Month: {$usedCalls} / {$monthlyCap} cap (250 hard limit)");
            $this->line('Quota High Restriction (>=85%): '.($isQuotaHigh ? 'ACTIVE (high priority / active campaigns only)' : 'Inactive'));
            $this->line("Weekly Call Allowance: {$weeklyAllowance}");
            $this->line('Selected Products for SerpApi Refresh: '.$productsToRefresh->count().' (projected calls: '.($productsToRefresh->count() * 2).')');

            $headers = ['ID', 'Product Name', 'Current Score', 'Rank', 'Last Refreshed', 'Priority Reason'];
            $rows = $productsToRefresh->map(function (WatchedProduct $p) {
                $reason = $p->last_refreshed_at === null
                    ? 'Never refreshed'
                    : ($p->score >= 50 ? 'High score' : 'Oldest refreshed');

                return [
                    $p->id,
                    $p->name,
                    $p->score ?? 'N/A',
                    $p->rank ?? 'N/A',
                    $p->last_refreshed_at?->toDateTimeString() ?? 'Never',
                    $reason,
                ];
            })->toArray();

            $this->table($headers, $rows);
            $this->info('Dry run completed. No external HTTP or database changes were made.');

            return self::SUCCESS;
        }

        $this->info("Starting Watching Products refresh run for {$budget->currentPeriod()}...");

        // 1. FX refresh
        $this->line('Step 1/7: Refreshing FX rates...');
        try {
            $fx->getRate('USD', 'CAD');
        } catch (\Throwable $e) {
            $this->warn("FX refresh error: {$e->getMessage()}");
        }

        // 2. Listing checks
        $this->line('Step 2/7: Running listing checks...');
        $allActive = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->get();

        foreach ($allActive as $product) {
            $checkResult = $listingChecker->check($product);
            if ($checkResult['listing_status']) {
                $product->listing_status = $checkResult['listing_status'];
            }
            if ($checkResult['brand_bidding'] && $product->brand_bidding->value === 'unknown') {
                $product->brand_bidding = $checkResult['brand_bidding'];
            }
            // Auto-detect bof_translation if not set and name passes target language checks
            if (! $product->bof_translation && $bofChecker->detectTranslationPass($product->name)) {
                $product->bof_translation = true;
            }
            $product->saveQuietly();

            // Safety Rule: Auto-pause campaigns if listing is down/expired
            if ($product->listing_status === ListingStatus::Expired) {
                $campaignSafety->pauseCampaignsForProduct(
                    $product,
                    dryRun: $isDryRun,
                    reason: 'Listing marked as unavailable/expired during weekly refresh.'
                );
            }
        }

        // 3. Budgeted SerpApi calls
        $serpCallsMade = 0;
        if (! $noSerp) {
            $this->line('Step 3/7: Running budgeted SerpApi calls...');
            foreach ($productsToRefresh as $product) {
                // Confirm budget safety
                if (! $budget->canSpendManual(2)) {
                    $this->warn('SerpApi budget ceiling reached. Stopping further API calls.');
                    break;
                }

                $forceFresh = ! empty($productId);
                $data = $serpClient->fetchProductData($product, forceFresh: $forceFresh, isManual: ! empty($productId));
                $serpCallsMade += 2;

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

                    // Light pass: sync base seeds, rules, and generic meanings only (no noisy SERP negatives)
                    $negativeGenerator->generateBase($product);
                } else {
                    // Even if SerpApi fails or 429, ensure base seeds and rules are synced
                    $negativeGenerator->generateBase($product);
                    if (! $product->bof_translation && $bofChecker->detectTranslationPass($product->name)) {
                        $product->bof_translation = true;
                    }
                }

                $product->last_refreshed_at = now();
                $product->saveQuietly();
            }
        } else {
            $this->line('Step 3/7: Skipping SerpApi calls (--no-serp passed).');
        }

        // 4. Rescore (including strategy recommendation if still auto)
        $this->line('Step 4/7: Rescoring active products...');
        foreach ($allActive as $product) {
            $product->refresh();

            // Re-evaluate strategy if auto
            if ($product->strategy_source === StrategySource::Auto || empty($product->strategy)) {
                $strategyRec = $strategyRecommender->recommend($product);
                $product->strategy = $strategyRec['strategy'];
                $product->strategy_source = StrategySource::Auto;
            }

            // Recalculate score
            $scoreResult = $scoreService->score($product);
            $product->score = $scoreResult['score'];
            $product->score_breakdown = $scoreResult['breakdown'];
            $product->score_flags = $scoreResult['flags'];
            $product->scored_at = now();
            $product->saveQuietly();

            // 6. One history row per product
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
                    'trends_30d' => $product->trends_30d,
                    'advertiser_count' => $product->advertiser_count,
                ],
                'created_at' => now(),
            ]);
        }

        // 5. Rank
        $this->line('Step 5/7: Recalculating watchlist ranks...');
        $rankService->recalculateRanks();

        // 7. Summary log line
        $totalCallsAfter = $budget->callsUsedThisMonth();
        $summary = "Watching Products refresh complete: {$allActive->count()} products rescored, {$productsToRefresh->count()} checked via SerpApi ({$serpCallsMade} calls made). Total monthly calls: {$totalCallsAfter}/250.";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
