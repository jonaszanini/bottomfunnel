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
     * Scope query to dismissed alerts.
     */
    public function scopeDismissed(Builder $query): Builder
    {
        return $query->whereNotNull('dismissed_at');
    }
}
