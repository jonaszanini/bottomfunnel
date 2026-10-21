<?php

namespace App\Services\WatchingProducts;

use App\Models\WatchedProduct;
use App\Services\WatchingProducts\Fx\FrankfurterFx;

class CampaignGuidance
{
    public function __construct(
        protected FrankfurterFx $fx = new FrankfurterFx
    ) {}

    /**
     * Compute display guidance for a given product.
     *
     * @return array<string, mixed>
     */
    public function forProduct(WatchedProduct $product): array
    {
        $amount = (float) ($product->payout_amount ?? $product->payout_cad ?? 0.0);
        $currency = $product->payout_currency ?? 'USD';

        $payoutUsd = $this->fx->toUsd($amount, $currency);
        $payoutCad = $this->fx->toCad($amount, $currency);

        $sales = (int) ($product->sales ?? 0);
        $checkouts = (int) ($product->checkouts ?? 0);
        $spend = (float) ($product->spend_amount ?? 0.0);
        $spendCurrency = $product->spend_currency ?? 'USD';
        $spendUsd = $this->fx->toUsd($spend, $spendCurrency);

        $commissionEarned = (float) ($product->commission_earned ?? 0.0);
        $commissionEarnedUsd = $this->fx->toUsd($commissionEarned, $currency);

        // Target CPA: 50-60% recommended, 40-70% allowed
        $targetCpaMinUsd = round($payoutUsd * 0.40, 2);
        $targetCpaRecMinUsd = round($payoutUsd * 0.50, 2);
        $targetCpaRecMaxUsd = round($payoutUsd * 0.60, 2);
        $targetCpaMaxUsd = round($payoutUsd * 0.70, 2);

        // Daily budget: 100-120% of target CPA
        $dailyBudgetMinUsd = round($targetCpaRecMinUsd * 1.00, 2);
        $dailyBudgetMaxUsd = round($targetCpaRecMaxUsd * 1.20, 2);

        // Stop-loss threshold calculation
        $stopLossThresholdUsd = 0.0;
        $stopLossStatus = 'active';
        $stopLossMessage = 'Within safety limits.';

        if ($sales === 0) {
            if ($checkouts === 0) {
                // Cut at 50% of commission without checkouts
                $stopLossThresholdUsd = round($payoutUsd * 0.50, 2);
                if ($spendUsd >= $stopLossThresholdUsd && $stopLossThresholdUsd > 0) {
                    $stopLossStatus = 'stop_loss_breached';
                    $stopLossMessage = 'Cut immediately: spend reached 50% commission with 0 checkouts.';
                } elseif ($spendUsd >= $stopLossThresholdUsd * 0.8) {
                    $stopLossStatus = 'approaching_stop_loss';
                    $stopLossMessage = 'Approaching cut threshold (50% commission).';
                }
            } else {
                // Cut at 80-100% of commission with checkouts
                $stopLossThresholdUsd = round($payoutUsd * 1.00, 2);
                if ($spendUsd >= $stopLossThresholdUsd && $stopLossThresholdUsd > 0) {
                    $stopLossStatus = 'stop_loss_breached';
                    $stopLossMessage = 'Cut immediately: spend reached 100% commission with 0 sales.';
                }
            }
        } elseif ($sales === 1) {
            // Stop at 70% of commission earned
            $stopLossThresholdUsd = round(($commissionEarnedUsd > 0 ? $commissionEarnedUsd : $payoutUsd) * 0.70, 2);
            if ($spendUsd >= $stopLossThresholdUsd && $stopLossThresholdUsd > 0) {
                $stopLossStatus = 'stop_loss_breached';
                $stopLossMessage = 'Pause: spend has exceeded 70% of first sale commission.';
            }
        } else {
            // Scaled campaign (2+ sales)
            $roi = $spendUsd > 0 ? (($commissionEarnedUsd - $spendUsd) / $spendUsd) * 100 : 0;
            if ($roi < 25.0) {
                $stopLossStatus = 'pause_scaled';
                $stopLossMessage = "Pause scaled campaign: current ROI is {$roi}% (below 25-30% threshold).";
            } elseif ($roi >= 60.0) {
                $stopLossStatus = 'optimizing_scaled';
                $stopLossMessage = "Campaign performing well: {$roi}% ROI (target: 60%+).";
            } else {
                $stopLossStatus = 'acceptable_scaled';
                $stopLossMessage = "Campaign running at {$roi}% ROI.";
            }
        }

        // Global alarm check
        $globalAlarm = $this->checkGlobalAlarm();

        return [
            'target_cpa' => [
                'recommended_range_usd' => "\${$targetCpaRecMinUsd} - \${$targetCpaRecMaxUsd}",
                'allowed_range_usd' => "\${$targetCpaMinUsd} - \${$targetCpaMaxUsd}",
                'rec_min_usd' => $targetCpaRecMinUsd,
                'rec_max_usd' => $targetCpaRecMaxUsd,
            ],
            'daily_budget' => [
                'recommended_range_usd' => "\${$dailyBudgetMinUsd} - \${$dailyBudgetMaxUsd}",
                'min_usd' => $dailyBudgetMinUsd,
                'max_usd' => $dailyBudgetMaxUsd,
            ],
            'stop_loss' => [
                'status' => $stopLossStatus,
                'threshold_usd' => $stopLossThresholdUsd,
                'current_spend_usd' => $spendUsd,
                'message' => $stopLossMessage,
            ],
            'global_alarm' => $globalAlarm,
        ];
    }

    /**
     * Global alarm: 5 products, or R$2,500-4,000 (~US$500) spent with 0 sales across watchlist.
     *
     * @return array{triggered: bool, reason: ?string, count_zero_sales: int, total_spend_usd: float}
     */
    public function checkGlobalAlarm(): array
    {
        $zeroSaleProducts = WatchedProduct::query()
            ->where('spend_amount', '>', 0)
            ->where('sales', 0)
            ->get(['id', 'spend_amount', 'spend_currency', 'sales']);

        $count = $zeroSaleProducts->count();
        $totalSpendUsd = 0.0;

        foreach ($zeroSaleProducts as $p) {
            $totalSpendUsd += $this->fx->toUsd((float) $p->spend_amount, $p->spend_currency ?? 'USD');
        }

        $triggered = false;
        $reason = null;

        if ($count >= 5) {
            $triggered = true;
            $reason = "Global Alarm: {$count} products tested with 0 sales.";
        } elseif ($totalSpendUsd >= 500.0) { // ~R$2,850+
            $triggered = true;
            $reason = 'Global Alarm: Total spend of $'.number_format($totalSpendUsd, 2).' USD reached with 0 sales.';
        }

        return [
            'triggered' => $triggered,
            'reason' => $reason,
            'count_zero_sales' => $count,
            'total_spend_usd' => round($totalSpendUsd, 2),
        ];
    }
}
