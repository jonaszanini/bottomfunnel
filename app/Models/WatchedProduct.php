<?php

namespace App\Models;

use App\Enums\BrandBiddingStatus;
use App\Enums\FlowspyTrafficTrend;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\ProductKind;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Enums\TrendsTrend;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchedProduct extends Model
{
    use HasFactory;

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
        'notes',
        'last_reviewed_at',
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
            'brand_bidding' => BrandBiddingStatus::class,
            'true_bof' => TrueBofStatus::class,
            'listing_status' => ListingStatus::class,
            'product_kind' => ProductKind::class,
            'trends_trend' => TrendsTrend::class,
            'flowspy_traffic_trend' => FlowspyTrafficTrend::class,
            'payout_model' => PayoutModel::class,
            'strategy' => Strategy::class,
            'strategy_source' => StrategySource::class,
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
        ];
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
}
