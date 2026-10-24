<?php

namespace App\Models;

use Database\Factories\CampaignAlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAlert extends Model
{
    /** @use HasFactory<CampaignAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'product_name',
        'product_id',
        'track_id',
        'campaign_id',
        'account_id',
        'severity',
        'reason',
        'message',
        'spend_cad',
        'payout_cad',
        'threshold_cad',
        'window_days',
        'conversions_count',
        'action_taken',
        'details',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'spend_cad' => 'decimal:2',
            'payout_cad' => 'decimal:2',
            'threshold_cad' => 'decimal:2',
            'window_days' => 'integer',
            'conversions_count' => 'integer',
            'details' => 'array',
            'dismissed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(WatchedProduct::class, 'product_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'track_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'account_id');
    }

    /**
     * Scope query to un-dismissed active alerts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Scope query to un-dismissed active alerts (alias for active).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Scope query to a specific Google Ads campaign.
     */
    public function scopeForCampaign(Builder $query, string $campaignId): Builder
    {
        return $query->where('campaign_id', $campaignId);
    }

    /**
     * Scope query to a specific Track.
     */
    public function scopeForTrack(Builder $query, int $trackId): Builder
    {
        return $query->where('track_id', $trackId);
    }

    /**
     * Find an existing unresolved alert matching alert type and campaign/track/product.
     */
    public static function findOpenAlert(string $type, ?string $campaignId = null, ?int $trackId = null, ?int $productId = null): ?self
    {
        $query = static::query()->active()->where('type', $type);

        if (! empty($campaignId)) {
            $query->where('campaign_id', $campaignId);
            if ($trackId !== null) {
                $query->where('track_id', $trackId);
            }
        } elseif ($trackId !== null) {
            $query->where('track_id', $trackId);
        } elseif ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query->first();
    }

    /**
     * Determine if alert is still open and unresolved.
     */
    public function isUnresolved(): bool
    {
        return $this->dismissed_at === null;
    }

    /**
     * Scope query to dismissed alerts.
     */
    public function scopeDismissed(Builder $query): Builder
    {
        return $query->whereNotNull('dismissed_at');
    }
}
