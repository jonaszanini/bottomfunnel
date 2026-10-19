<?php

namespace App\Services\Reporting;

use App\Models\AdSpend;
use App\Models\Conversion;
use App\Models\IpBlock;
use App\Models\Track;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardReport
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        $trackIds = $this->trackIds($filters);

        $visits = Visit::query()
            ->whereBetween('landed_at', [$from, $to])
            ->when($trackIds !== null, fn (Builder $query) => $query->whereIn('track_id', $trackIds))
            ->when($filters['page_type'] ?? null, fn (Builder $query, string $type) => $query->where('page_type', $type));

        $visitRows = (clone $visits)->get([
            'id', 'track_id', 'page_type', 'time_on_page_seconds', 'engagement', 'flagged', 'auto_blocked', 'ip_address', 'fraud_reason', 'landed_at',
        ]);

        $conversions = Conversion::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($trackIds !== null, fn (Builder $query) => $query->whereIn('track_id', $trackIds))
            ->when($filters['conversion_status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
        $conversionRows = (clone $conversions)->get(['track_id', 'commission_value_cad', 'status']);

        $spendRows = AdSpend::query()
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->when($trackIds !== null, fn (Builder $query) => $query->whereIn('track_id', $trackIds))
            ->get(['track_id', 'amount_cad', 'start_date', 'end_date', 'note']);

        $tracks = Track::query()
            ->when($trackIds !== null, fn (Builder $query) => $query->whereIn('id', $trackIds))
            ->orderBy('product_name')
            ->get();

        $byType = [];
        foreach ($visitRows as $visit) {
            $type = $visit->page_type;
            $byType[$type] ??= ['page_type' => $type, 'sessions' => 0, 'time_total' => 0, 'cta_sessions' => 0];
            $byType[$type]['sessions']++;
            $byType[$type]['time_total'] += (int) $visit->time_on_page_seconds;
            if ((int) ($visit->engagement['cta_clicks'] ?? 0) > 0) {
                $byType[$type]['cta_sessions']++;
            }
        }

        $pageTypes = [];
        foreach ($byType as $row) {
            $pageTypes[] = [
                'page_type' => $row['page_type'],
                'label' => config('flowhub.page_types.'.$row['page_type'], $row['page_type']),
                'sessions' => $row['sessions'],
                'average_time' => $row['sessions'] ? round($row['time_total'] / $row['sessions'], 1) : 0,
                'cta_rate' => $row['sessions'] ? round($row['cta_sessions'] / $row['sessions'] * 100, 1) : 0,
            ];
        }

        $rows = [];
        $flags = [];
        foreach ($tracks as $track) {
            $trackVisits = $visitRows->where('track_id', $track->id);
            $trackConversions = $conversionRows->where('track_id', $track->id);
            $commission = round((float) $trackConversions->sum('commission_value_cad'), 2);
            $spend = round((float) $spendRows->where('track_id', $track->id)->sum('amount_cad'), 2);
            $sessions = $trackVisits->count();
            $ctaSessions = $trackVisits->filter(fn (Visit $visit): bool => (int) ($visit->engagement['cta_clicks'] ?? 0) > 0)->count();
            $profit = round($commission - $spend, 2);
            $roi = $spend > 0 ? round($profit / $spend * 100, 1) : null;
            $conversionCount = $trackConversions->whereNotIn('status', ['unmatched'])->count();
            $rows[] = [
                'id' => $track->id,
                'product_name' => $track->product_name,
                'strategy' => config('flowhub.strategies.'.$track->strategy, $track->strategy),
                'page_type' => config('flowhub.page_types.'.$track->page_type, $track->page_type),
                'platform' => config('flowhub.platforms.'.$track->platform, $track->platform),
                'sessions' => $sessions,
                'average_time' => $sessions ? round($trackVisits->avg('time_on_page_seconds'), 1) : 0,
                'cta_rate' => $sessions ? round($ctaSessions / $sessions * 100, 1) : 0,
                'under_10' => $trackVisits->where('time_on_page_seconds', '<', 10)->count(),
                'conversions' => $conversionCount,
                'commission' => $commission,
                'spend' => $spend,
                'profit' => $profit,
                'roi' => $roi,
            ];
            foreach ($this->flags($track, $conversionCount, $commission, $spend) as $flag) {
                $flags[] = $flag;
            }
        }

        $sessionCount = $visitRows->count();
        $ctaSessions = $visitRows->filter(fn (Visit $visit): bool => (int) ($visit->engagement['cta_clicks'] ?? 0) > 0)->count();
        $commission = round((float) $conversionRows->sum('commission_value_cad'), 2);
        $spend = round((float) $spendRows->sum('amount_cad'), 2);
        $profit = round($commission - $spend, 2);

        return [
            'summary' => [
                'visits' => $sessionCount,
                'sessions' => $sessionCount,
                'average_time' => $sessionCount ? round($visitRows->avg('time_on_page_seconds'), 1) : 0,
                'cta_rate' => $sessionCount ? round($ctaSessions / $sessionCount * 100, 1) : 0,
                'under_10' => $visitRows->where('time_on_page_seconds', '<', 10)->count(),
                'conversions' => $conversionRows->whereNotIn('status', ['unmatched'])->count(),
                'commission' => $commission,
                'spend' => $spend,
                'profit' => $profit,
                'roi' => $spend > 0 ? round($profit / $spend * 100, 1) : null,
                'uploads' => $conversionRows->groupBy('status')->map->count(),
            ],
            'tracks' => $rows,
            'page_types' => $pageTypes,
            'flags' => $flags,
            'blocked' => IpBlock::query()
                ->with('track:id,product_name')
                ->where('status', 'applied')
                ->when($trackIds !== null, fn (Builder $query) => $query->whereIn('track_id', $trackIds))
                ->whereBetween('created_at', [$from, $to])
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (IpBlock $block): array => [
                    'id' => $block->id,
                    'ip_address' => $block->ip_address,
                    'reason' => $block->reason,
                    'product_name' => $block->track?->product_name,
                    'created_at' => $block->created_at?->toDateTimeString(),
                ])
                ->all(),
            'alerts' => $visitRows
                ->filter(fn (Visit $visit): bool => $visit->flagged || $visit->auto_blocked)
                ->take(50)
                ->map(fn (Visit $visit): array => [
                    'ip_address' => $visit->ip_address,
                    'reason' => $visit->fraud_reason,
                    'landed_at' => $visit->landed_at?->toDateTimeString(),
                    'auto_blocked' => $visit->auto_blocked,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    private function trackIds(array $filters): ?array
    {
        $query = Track::query();
        $scoped = false;
        if (! empty($filters['track_id'])) {
            $query->where('id', $filters['track_id']);
            $scoped = true;
        }
        if (! empty($filters['strategy'])) {
            $query->where('strategy', $filters['strategy']);
            $scoped = true;
        }
        if (! empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
            $scoped = true;
        }
        if (! empty($filters['page_type'])) {
            $query->where('page_type', $filters['page_type']);
            $scoped = true;
        }
        if (! $scoped) {
            return null;
        }

        return $query->pluck('id')->all();
    }

    /**
     * @return list<array{track_id: int, product_name: string, message: string}>
     */
    private function flags(Track $track, int $conversions, float $commission, float $spend): array
    {
        $flags = [];
        $typical = (float) $track->typical_commission_cad;
        if ($conversions === 0 && $typical > 0 && $spend >= ($typical * 0.5)) {
            $flags[] = [
                'track_id' => $track->id,
                'product_name' => $track->product_name,
                'message' => 'No conversions, and spend has reached 50 percent of the typical commission for one sale.',
            ];
        }
        if ($conversions >= 1 && $commission > 0 && $spend >= ($commission * 0.7)) {
            $flags[] = [
                'track_id' => $track->id,
                'product_name' => $track->product_name,
                'message' => 'Spend has reached 70 percent of recorded commission.',
            ];
        }
        if ($conversions >= 1 && $commission <= 0 && $spend > 0) {
            $flags[] = [
                'track_id' => $track->id,
                'product_name' => $track->product_name,
                'message' => 'Spend has reached 70 percent of recorded commission.',
            ];
        }
        if ($spend > 0) {
            $roi = ($commission - $spend) / $spend * 100;
            if ($roi <= 30) {
                $flags[] = [
                    'track_id' => $track->id,
                    'product_name' => $track->product_name,
                    'message' => 'ROI is at or under the 30 percent floor.',
                ];
            }
        }

        return $flags;
    }
}
