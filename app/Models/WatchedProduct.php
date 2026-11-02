<?php

namespace App\Models;

use App\Enums\AffiliateApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\BrandBiddingStatus;
use App\Enums\ComplianceRisk;
use App\Enums\FlowspyTrafficTrend;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\ProductKind;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Enums\TrendsTrend;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchedProduct extends Model
{
    use HasFactory;

    protected $attributes = [
        'affiliate_application_status' => 'not_requested',
    ];

    protected $fillable = [
        'name',
        'status',
        'platforms',
        'payout_amount',
        'payout_currency',
        'payout_model',
        'payout_cad',
        'payout_notes',
        'gravity',
        'platform',
        'listing_url',
        'listing_status',
        'cancel_rate',
        'page_type',
        'funnel_flags',
        'product_kind',
        'esteira',
        'restricted_category',
        'strategy',
        'strategy_source',
        'brand_bidding',
        'true_bof',
        'bof_translation',
        'bof_vpn_serp',
        'bof_image_match_pct',
        'bof_qualifier',
        'bof_homonym',
        'geos',
        'negatives',
        /** @deprecated Use approval_status instead */
        'approval_required',
        'demand_notes',
        'trends_7d',
        'trends_30d',
        'trends_90d',
        'trends_trend',
        'flowspy_traffic_trend',
        'kp_volume',
        'kp_yoy',
        'kp_3m',
        'kp_cpc_amount',
        'kp_cpc_currency',
        'brand_cpc_amount',
        'brand_cpc_currency',
        'est_cvr',
        'advertiser_count',
        'vendor_bids_brand',
        'spend_amount',
        'spend_currency',
        'sales',
        'checkouts',
        'commission_earned',
        'score',
        'score_breakdown',
        'score_flags',
        'rank',
        'scored_at',
        'last_refreshed_at',
        'refresh_priority',
        'sales_page_url',
        'affiliate_link',
        'network_offer_url',
        'notes',
        'last_reviewed_at',
        'allowed_geos',
        'compliance_risk',
        'compliance_notes',
        /** @deprecated Use network_approval_status instead */
        'network_approval_required',
        'payouts_by_platform',
        'strategy_phase2',
        'strategy_switch_trigger',
        'recheck_at',
        'promotion_criteria',
        'approval_status',
        'network_approval_status',
        'search_volume_by_geo',
        'search_volume_total',
        'search_volume_is_range',
        'search_volume_keyword',
        'search_volume_checked_at',
        'search_volume_error',
        'search_volume_error_at',
        'affiliate_application_status',
        'affiliate_application_requested_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WatchedProductStatus::class,
            'compliance_risk' => ComplianceRisk::class,
            'network_approval_required' => 'boolean',
            'allowed_geos' => 'array',
            'payouts_by_platform' => 'array',
            'brand_bidding' => BrandBiddingStatus::class,
            'true_bof' => TrueBofStatus::class,
            'listing_status' => ListingStatus::class,
            'product_kind' => ProductKind::class,
            'trends_trend' => TrendsTrend::class,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::class,
            'payout_model' => PayoutModel::class,
            'strategy' => Strategy::class,
            'strategy_phase2' => Strategy::class,
            'strategy_source' => StrategySource::class,
            'recheck_at' => 'date',
            'approval_status' => ApprovalStatus::class,
            'network_approval_status' => ApprovalStatus::class,
            'payout_amount' => 'decimal:2',
            'payout_cad' => 'decimal:2',
            'gravity' => 'decimal:2',
            'cancel_rate' => 'decimal:2',
            'funnel_flags' => 'array',
            'esteira' => 'boolean',
            'restricted_category' => 'boolean',
            'bof_translation' => 'boolean',
            'bof_vpn_serp' => 'boolean',
            'bof_image_match_pct' => 'integer',
            'bof_homonym' => 'boolean',
            'approval_required' => 'boolean',
            'trends_7d' => 'decimal:2',
            'trends_30d' => 'decimal:2',
            'trends_90d' => 'decimal:2',
            'kp_volume' => 'integer',
            'kp_yoy' => 'decimal:2',
            'kp_3m' => 'decimal:2',
            'kp_cpc_amount' => 'decimal:2',
            'brand_cpc_amount' => 'decimal:2',
            'est_cvr' => 'decimal:4',
            'advertiser_count' => 'integer',
            'vendor_bids_brand' => 'boolean',
            'spend_amount' => 'decimal:2',
            'sales' => 'integer',
            'checkouts' => 'integer',
            'commission_earned' => 'decimal:2',
            'score' => 'integer',
            'score_breakdown' => 'array',
            'score_flags' => 'array',
            'rank' => 'integer',
            'scored_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'refresh_priority' => 'integer',
            'last_reviewed_at' => 'datetime',
            'search_volume_by_geo' => 'array',
            'search_volume_total' => 'integer',
            'search_volume_is_range' => 'boolean',
            'search_volume_checked_at' => 'datetime',
            'search_volume_error_at' => 'datetime',
            'affiliate_application_status' => AffiliateApplicationStatus::class,
            'affiliate_application_requested_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            if ($product->isDirty('approval_status') && $product->approval_status !== null) {
                $product->approval_required = ($product->approval_status === ApprovalStatus::Yes);
            }
            if ($product->isDirty('network_approval_status') && $product->network_approval_status !== null) {
                $product->network_approval_required = ($product->network_approval_status === ApprovalStatus::Yes);
            }
            if (
                $product->isDirty('affiliate_application_status') &&
                in_array($product->affiliate_application_status, [
                    AffiliateApplicationStatus::Pending,
                    AffiliateApplicationStatus::Approved,
                    AffiliateApplicationStatus::Rejected,
                ], true) &&
                empty($product->affiliate_application_requested_at)
            ) {
                $product->affiliate_application_requested_at = now('America/Toronto')->toDateString();
            }
        });
    }

    /**
     * Platforms attribute: normalized array in PHP, JSON string in database.
     */
    protected function platforms(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if (empty($value)) {
                    return [];
                }

                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: function (mixed $value): string {
                if (empty($value)) {
                    return json_encode([]);
                }

                if (is_string($value)) {
                    $parts = array_values(array_filter(array_map('trim', explode(',', $value))));

                    return json_encode($parts);
                }

                if (is_array($value)) {
                    $parts = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));

                    return json_encode($parts);
                }

                return json_encode([]);
            }
        );
    }

    /**
     * Geos attribute: normalized array in PHP, JSON string in database.
     */
    protected function geos(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if (empty($value)) {
                    return [];
                }

                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: function (mixed $value): string {
                if (empty($value)) {
                    return json_encode([]);
                }

                if (is_string($value)) {
                    $parts = array_values(array_filter(array_map(fn ($item) => strtoupper(trim($item)), explode(',', $value))));

                    return json_encode($parts);
                }

                if (is_array($value)) {
                    $parts = array_values(array_filter(array_map(fn ($item) => strtoupper(trim((string) $item)), $value)));

                    return json_encode($parts);
                }

                return json_encode([]);
            }
        );
    }

    /**
     * Allowed Geos attribute: normalized uppercase array in PHP, JSON string in database.
     */
    protected function allowedGeos(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                if (empty($value)) {
                    return [];
                }

                if (is_array($value)) {
                    return $value;
                }

                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: function (mixed $value): string {
                if (empty($value)) {
                    return json_encode([]);
                }

                if (is_string($value)) {
                    $parts = array_values(array_filter(array_map(fn ($item) => strtoupper(trim((string) $item)), explode(',', $value))));

                    return json_encode($parts);
                }

                if (is_array($value)) {
                    $parts = array_values(array_filter(array_map(fn ($item) => strtoupper(trim((string) $item)), $value)));

                    return json_encode($parts);
                }

                return json_encode([]);
            }
        );
    }

    /**
     * Payouts by platform attribute: normalized list of platform payout entries.
     * Backwards-compatible: rows can have {platform, geo_tier, amount, currency, model}.
     * Old rows {platform, payout, currency} are automatically normalized on read.
     */
    protected function payoutsByPlatform(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                if (empty($value)) {
                    return [];
                }

                $data = is_array($value) ? $value : json_decode((string) $value, true);
                if (! is_array($data)) {
                    return [];
                }

                $normalized = [];
                foreach ($data as $row) {
                    if (is_array($row) && ! empty($row['platform'])) {
                        $amount = isset($row['amount']) && is_numeric($row['amount'])
                            ? (float) $row['amount']
                            : (isset($row['payout']) && is_numeric($row['payout']) ? (float) $row['payout'] : 0.0);

                        $normalized[] = [
                            'platform' => trim((string) $row['platform']),
                            'geo_tier' => trim((string) ($row['geo_tier'] ?? '')),
                            'amount' => $amount,
                            'payout' => $amount,
                            'currency' => strtoupper(trim((string) ($row['currency'] ?? 'USD'))),
                            'model' => trim((string) ($row['model'] ?? 'cpa')),
                        ];
                    }
                }

                return array_values($normalized);
            },
            set: function (mixed $value): string {
                if (empty($value)) {
                    return json_encode([]);
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : [];
                }

                if (is_array($value)) {
                    $sanitized = [];
                    foreach ($value as $row) {
                        if (is_array($row) && ! empty($row['platform'])) {
                            $amount = isset($row['amount']) && is_numeric($row['amount'])
                                ? (float) $row['amount']
                                : (isset($row['payout']) && is_numeric($row['payout']) ? (float) $row['payout'] : 0.0);

                            $sanitized[] = [
                                'platform' => trim((string) $row['platform']),
                                'geo_tier' => trim((string) ($row['geo_tier'] ?? '')),
                                'amount' => $amount,
                                'payout' => $amount,
                                'currency' => strtoupper(trim((string) ($row['currency'] ?? 'USD'))),
                                'model' => trim((string) ($row['model'] ?? 'cpa')),
                            ];
                        }
                    }

                    return json_encode(array_values($sanitized));
                }

                return json_encode([]);
            }
        );
    }

    /**
     * Determine effective payout for scoring, matching allowed_geos when set,
     * otherwise highest payout across tiers.
     *
     * @return array{amount: float, currency: string, usd: float, geo_tier: string}
     */
    public function scoringPayout(?FrankfurterFx $fx = null): array
    {
        $fx ??= app(FrankfurterFx::class);
        $candidates = [];

        $topAmount = (float) ($this->payout_amount ?? $this->payout_cad ?? 0.0);
        $topCurrency = $this->payout_currency ?? 'USD';
        if ($topAmount > 0) {
            $candidates[] = [
                'amount' => $topAmount,
                'currency' => $topCurrency,
                'geo_tier' => '',
                'usd' => Money::make($topAmount, $topCurrency)->toUsd($fx),
            ];
        }

        $tiers = (array) ($this->payouts_by_platform ?? []);
        foreach ($tiers as $tier) {
            $tierAmount = (float) ($tier['amount'] ?? $tier['payout'] ?? 0.0);
            $tierCurrency = (string) ($tier['currency'] ?? 'USD');
            $geoTier = trim((string) ($tier['geo_tier'] ?? ''));
            if ($tierAmount > 0) {
                $candidates[] = [
                    'amount' => $tierAmount,
                    'currency' => $tierCurrency,
                    'geo_tier' => $geoTier,
                    'usd' => Money::make($tierAmount, $tierCurrency)->toUsd($fx),
                ];
            }
        }

        if (empty($candidates)) {
            return [
                'amount' => 0.0,
                'currency' => 'USD',
                'usd' => 0.0,
                'geo_tier' => '',
            ];
        }

        $allowedGeos = array_values(array_filter(array_map('strtoupper', (array) ($this->allowed_geos ?? []))));

        if (! empty($allowedGeos)) {
            $geoMatches = [];
            foreach ($candidates as $candidate) {
                $candidateTier = strtoupper($candidate['geo_tier']);
                if ($candidateTier === '') {
                    continue;
                }
                foreach ($allowedGeos as $geo) {
                    if ($candidateTier === $geo || str_contains($candidateTier, $geo)) {
                        $geoMatches[] = $candidate;
                        break;
                    }
                }
            }

            if (! empty($geoMatches)) {
                usort($geoMatches, fn ($a, $b) => $b['usd'] <=> $a['usd']);

                return $geoMatches[0];
            }
        }

        usort($candidates, fn ($a, $b) => $b['usd'] <=> $a['usd']);

        return $candidates[0];
    }

    /**
     * @return HasMany<ProductScoreHistory, $this>
     */
    public function scoreHistory(): HasMany
    {
        return $this->hasMany(ProductScoreHistory::class, 'product_id')->orderByDesc('created_at');
    }

    /**
     * @return HasMany<ProductNegativeKeyword, $this>
     */
    public function negativeKeywords(): HasMany
    {
        return $this->hasMany(ProductNegativeKeyword::class, 'product_id')->orderBy('term');
    }

    /**
     * @return HasMany<Track, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * Scope a query to apply user filters.
     *
     * @param  Builder<WatchedProduct>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function (Builder $query, string $search) {
            $query->where('name', 'like', '%'.trim($search).'%');
        });

        $query->when($filters['status'] ?? null, function (Builder $query, string $status) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        });

        $query->when($filters['strategy'] ?? null, function (Builder $query, string $strategy) {
            if ($strategy !== 'all') {
                $query->where('strategy', $strategy);
            }
        });

        $query->when($filters['product_kind'] ?? null, function (Builder $query, string $kind) {
            if ($kind !== 'all') {
                $query->where('product_kind', $kind);
            }
        });

        $query->when($filters['brand_bidding'] ?? null, function (Builder $query, string $brandBidding) {
            if ($brandBidding !== 'all') {
                $query->where('brand_bidding', $brandBidding);
            }
        });

        $query->when($filters['true_bof'] ?? null, function (Builder $query, string $trueBof) {
            if ($trueBof !== 'all') {
                $query->where('true_bof', $trueBof);
            }
        });

        $query->when($filters['platform'] ?? null, function (Builder $query, string $platform) {
            $cleanPlatform = trim($platform);
            if ($cleanPlatform !== '' && $cleanPlatform !== 'all') {
                $query->where(function (Builder $q) use ($cleanPlatform) {
                    $q->whereJsonContains('platforms', $cleanPlatform)
                        ->orWhere('platforms', 'like', '%'.$cleanPlatform.'%')
                        ->orWhere('platform', $cleanPlatform);
                });
            }
        });

        $query->when(! empty($filters['recheck_overdue']), function (Builder $query) {
            $query->whereNotNull('recheck_at')
                ->where('recheck_at', '<=', now()->toDateString());
        });

        $query->when($filters['affiliate_application_status'] ?? null, function (Builder $query, string $status) {
            if ($status !== 'all') {
                $query->where('affiliate_application_status', $status);
            }
        });

        $query->when($filters['volume'] ?? null, function (Builder $query, string $volume) {
            if ($volume === '1k') {
                $query->whereNotNull('search_volume_total')->where('search_volume_total', '>=', 1000);
            } elseif ($volume === '10k') {
                $query->whereNotNull('search_volume_total')->where('search_volume_total', '>=', 10000);
            } elseif ($volume === 'unknown') {
                $query->whereNull('search_volume_total');
            }
        });

        $query->when(($filters['sort'] ?? null) === 'search_volume_total', function (Builder $query) use ($filters) {
            $dir = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $query->orderBy('search_volume_total', $dir);
        });
    }

    /**
     * Determine target geos for search volume retrieval.
     * Uses allowed_geos intersected with the configured geo map;
     * falls back to default_geos (US, CA) if empty.
     *
     * @return list<string>
     */
    public function searchVolumeGeos(): array
    {
        $configuredGeos = array_keys((array) config('flowhub.keyword_volume.geos', [
            'US' => '2840',
            'CA' => '2124',
            'GB' => '2826',
            'AU' => '2036',
        ]));

        $allowed = array_values(array_filter(array_map('strtoupper', (array) ($this->allowed_geos ?? []))));
        $intersected = array_values(array_intersect($allowed, $configuredGeos));

        if (! empty($intersected)) {
            return $intersected;
        }

        return (array) config('flowhub.keyword_volume.default_geos', ['US', 'CA']);
    }

    /**
     * Effective keyword for search volume query.
     * Overrides queried keyword; default is product name.
     */
    public function effectiveSearchVolumeKeyword(): string
    {
        $kw = trim((string) ($this->search_volume_keyword ?? ''));

        return $kw !== '' ? $kw : trim((string) $this->name);
    }

    /**
     * Mark this product as reviewed at the current timestamp.
     */
    public function markReviewed(?CarbonInterface $timestamp = null): void
    {
        $this->update([
            'last_reviewed_at' => $timestamp ?? now(),
        ]);
    }

    public function isRefreshProcessing(): bool
    {
        return ($this->score_flags['refresh_status'] ?? null) === 'processing';
    }

    public function isRefreshFailed(): bool
    {
        return ($this->score_flags['refresh_status'] ?? null) === 'failed';
    }

    public function refreshError(): ?string
    {
        return $this->score_flags['refresh_error'] ?? null;
    }

    public function trendsTrendLabel(): string
    {
        $hasMetrics = $this->trends_7d !== null || $this->trends_30d !== null || $this->trends_90d !== null;
        $isZeroDemand = $hasMetrics
            && ((float) ($this->trends_7d ?? 0) <= 0)
            && ((float) ($this->trends_30d ?? 0) <= 0)
            && ((float) ($this->trends_90d ?? 0) <= 0);

        if ($isZeroDemand) {
            return 'Unknown';
        }

        if ($this->trends_trend === null) {
            return 'Unknown';
        }

        return $this->trends_trend->label();
    }

    /**
     * Effective trend enum taking null/zero trends volume into account.
     */
    public function effectiveTrendsTrend(): TrendsTrend
    {
        $hasMetrics = $this->trends_7d !== null || $this->trends_30d !== null || $this->trends_90d !== null;
        $isZeroDemand = $hasMetrics
            && ((float) ($this->trends_7d ?? 0) <= 0)
            && ((float) ($this->trends_30d ?? 0) <= 0)
            && ((float) ($this->trends_90d ?? 0) <= 0);

        if ($isZeroDemand) {
            return TrendsTrend::Unknown;
        }

        return $this->trends_trend ?? TrendsTrend::Unknown;
    }
}
