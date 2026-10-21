<?php

namespace App\Services\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\WatchedProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class QuotaBudget
{
    /**
     * Get the current period YYYY-MM string.
     */
    public function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /**
     * Get calls used this month.
     */
    public function callsUsedThisMonth(): int
    {
        $usage = ApiUsage::query()
            ->where('provider', 'serpapi')
            ->where('period', $this->currentPeriod())
            ->first();

        return $usage ? (int) $usage->calls : 0;
    }

    /**
     * Increment the SerpApi call counter atomically.
     */
    public function recordCall(int $count = 1): int
    {
        $period = $this->currentPeriod();

        return DB::transaction(function () use ($period, $count) {
            $usage = ApiUsage::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['provider' => 'serpapi', 'period' => $period],
                    ['calls' => 0]
                );

            $usage->increment('calls', $count);

            return (int) $usage->fresh()->calls;
        });
    }

    /**
     * Check if the automated weekly run can spend calls.
     */
    public function canSpendWeekly(int $neededCalls = 2): bool
    {
        $cap = (int) config('watching.serpapi_monthly_cap', 230);
        $used = $this->callsUsedThisMonth();

        return ($used + $neededCalls) <= $cap;
    }

    /**
     * Check if a manual refresh can spend calls (up to total limit of 250).
     */
    public function canSpendManual(int $neededCalls = 2): bool
    {
        $limit = (int) config('watching.serpapi_total_limit', 250);
        $used = $this->callsUsedThisMonth();

        return ($used + $neededCalls) <= $limit;
    }

    /**
     * Calculate allowed budget for this weekly run.
     * remaining_month ÷ remaining_weeks_in_month.
     */
    public function weeklyCallAllowance(): int
    {
        $cap = (int) config('watching.serpapi_monthly_cap', 230);
        $used = $this->callsUsedThisMonth();
        $remaining = max(0, $cap - $used);

        $now = now();
        $daysRemaining = max(1, $now->daysInMonth - $now->day);
        $weeksRemaining = max(1, (int) ceil($daysRemaining / 7));

        return (int) floor($remaining / $weeksRemaining);
    }

    /**
     * Select products to refresh via SerpApi in priority order.
     * Priority: never-refreshed newest, then highest score, then oldest last_refreshed_at.
     * Skip archived and hard-discarded products.
     *
     * @return Collection<int, WatchedProduct>
     */
    public function selectProductsForWeeklyRefresh(): Collection
    {
        $allowance = $this->weeklyCallAllowance();
        $maxProducts = (int) floor($allowance / 2); // 2 calls per product

        if ($maxProducts <= 0) {
            return new Collection;
        }

        // Candidates: non-rejected and not hard-discarded (homonym)
        $candidates = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->where('bof_homonym', false)
            ->get();

        // Sort candidates by priority order
        $sorted = $candidates->sort(function (WatchedProduct $a, WatchedProduct $b) {
            // Priority 1: Never-refreshed newest products
            $aNever = ($a->last_refreshed_at === null);
            $bNever = ($b->last_refreshed_at === null);

            if ($aNever && ! $bNever) {
                return -1;
            }
            if (! $aNever && $bNever) {
                return 1;
            }
            if ($aNever && $bNever) {
                return $b->created_at <=> $a->created_at;
            }

            // Priority 2: Highest score
            $scoreA = $a->score ?? 0;
            $scoreB = $b->score ?? 0;
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            // Priority 3: Oldest last_refreshed_at
            return $a->last_refreshed_at <=> $b->last_refreshed_at;
        })->values();

        return $sorted->take($maxProducts);
    }
}
