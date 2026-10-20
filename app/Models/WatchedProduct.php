<?php

namespace App\Models;

use App\Enums\BrandBiddingStatus;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchedProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'platforms',
        'payout_cad',
        'payout_notes',
        'page_type',
        'brand_bidding',
        'true_bof',
        'geos',
        'negatives',
        'approval_required',
        'demand_notes',
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
            'payout_cad' => 'decimal:2',
            'approval_required' => 'boolean',
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
                        ->orWhere('platforms', 'like', '%'.$cleanPlatform.'%');
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
}
