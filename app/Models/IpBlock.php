<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'track_id',
        'google_ads_account_id',
        'ip_address',
        'fraud_score',
        'is_subnet',
        'reason',
        'campaign_id',
        'criterion_resource_name',
        'status',
        'last_error',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_id' => 'integer',
            'google_ads_account_id' => 'integer',
            'fraud_score' => 'integer',
            'is_subnet' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (IpBlock $block): void {
            if ($block->ip_address && str_contains($block->ip_address, '/')) {
                $block->is_subnet = true;
            }
        });
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsCampaign::class, 'campaign_id', 'campaign_id');
    }

    public function scopeApplied(Builder $query): void
    {
        $query->where('status', 'applied');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    public function scopeWhitelisted(Builder $query): void
    {
        $query->where('status', 'whitelisted');
    }

    public function scopeConsolidated(Builder $query): void
    {
        $query->where('status', 'consolidated');
    }

    public function scopeRotated(Builder $query): void
    {
        $query->where('status', 'rotated');
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('status', 'failed');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['applied', 'pending', 'whitelisted']);
    }

    public function scopeSubnets(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('is_subnet', true)
                ->orWhere('ip_address', 'like', '%/%');
        });
    }

    public function scopeForTrack(Builder $query, int $trackId): void
    {
        $query->where('track_id', $trackId);
    }

    public function scopeForCampaign(Builder $query, string $campaignId): void
    {
        $query->where('campaign_id', $campaignId);
    }

    public function scopeForIp(Builder $query, string $ipAddress): void
    {
        $query->where('ip_address', $ipAddress);
    }

    public function isSubnet(): bool
    {
        return (bool) $this->is_subnet || str_contains((string) $this->ip_address, '/');
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isWhitelisted(): bool
    {
        return $this->status === 'whitelisted';
    }

    public function isConsolidated(): bool
    {
        return $this->status === 'consolidated';
    }

    public function isRotated(): bool
    {
        return $this->status === 'rotated';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }
}
