<?php

namespace App\Services\WatchingProducts;

use App\Enums\WatchedProductStatus;
use App\Models\ApiUsage;
use App\Models\Track;
use App\Models\WatchedProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
     * Increment the SerpApi call counter atomically within a distributed lock.
     */
    public function recordCall(int $count = 1): int
    {
        $period = $this->currentPeriod();

        return Cache::lock('serpapi_quota_lock', 10)->block(5, function () use ($period, $count) {
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
        });
    }

    /**
     * Atomically check if calls are available and deduct them under a distributed lock.
     * Prevents race conditions from exceeding monthly or manual limits across queue workers.
     */
    public function atomicSpend(int $neededCalls = 1, bool $isManual = false): bool
    {
        return Cache::lock('serpapi_quota_lock', 10)->block(5, function () use ($neededCalls, $isManual) {
            $cap = $isManual
                ? (int) config('watching.serpapi_total_limit', 250)
                : (int) config('watching.serpapi_monthly_cap', 230);

            $period = $this->currentPeriod();

            return DB::transaction(function () use ($period, $neededCalls, $cap) {
                $usage = ApiUsage::query()
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['provider' => 'serpapi', 'period' => $period],
                        ['calls' => 0]
                    );

                if (((int) $usage->calls + $neededCalls) > $cap) {
                    return false;
                }

                $usage->increment('calls', $neededCalls);

                return true;
            });
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
     * Check if quota usage has reached or exceeded the configured threshold (default 85%).
     */
    public function isQuotaHigh(?float $thresholdPct = null): bool
    {
        $thresholdPct ??= (float) config('watching.serpapi_quota_high_threshold', 0.85);
        $cap = (int) config('watching.serpapi_monthly_cap', 230);
        if ($cap <= 0) {
            return false;
        }

        return ($this->callsUsedThisMonth() / $cap) >= $thresholdPct;
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
     * When quota is at or above 85% of limit, automatically restricts refreshes to high priority
     * or active campaign products only.
     *
     * @return Collection<int, WatchedProduct>
     */
    public function selectProductsForWeeklyRefresh(?bool $highPriorityOnly = null): Collection
    {
        $allowance = $this->weeklyCallAllowance();
        $maxProducts = (int) floor($allowance / 2); // 2 calls per product

        if ($maxProducts <= 0) {
            return new Collection;
        }

        $restrictToHighPriority = $highPriorityOnly ?? $this->isQuotaHigh();

        // Candidates: non-rejected and not hard-discarded (homonym)
        $candidates = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->where('bof_homonym', false)
            ->get();

        if ($restrictToHighPriority) {
            // Pre-query active campaign product names to prevent N+1 queries
            $activeCampaignProductNames = Track::query()
                ->whereNotNull('campaign_ids')
                ->pluck('product_name')
                ->filter()
                ->map(fn ($name) => strtolower(trim((string) $name)))
                ->unique()
                ->all();

            $candidates = $candidates->filter(function (WatchedProduct $product) use ($activeCampaignProductNames) {
                // Active campaign product
                $cleanName = strtolower(trim((string) $product->name));
                if (in_array($cleanName, $activeCampaignProductNames, true)) {
                    return true;
                }

                // In Test status (campaign preparation / active testing)
                if ($product->status === WatchedProductStatus::Test) {
                    return true;
                }

                // Explicit high refresh priority
                if ((int) $product->refresh_priority > 0) {
                    return true;
                }

                // High potential score (>= 60)
                if ((int) ($product->score ?? 0) >= 60) {
                    return true;
                }

                return false;
            });
        }

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
