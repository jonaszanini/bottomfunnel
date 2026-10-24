<?php

namespace App\Models;

use App\Services\WatchingProducts\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'track_id',
        'visit_id',
        'order_id',
        'gclid',
        'amount',
        'currency',
        'fx_rate',
        'commission_value_cad',
        'status',
        'match_source',
        'upload_attempts',
        'request_id',
        'last_error',
        'raw_payload',
        'uploaded_at',
    ];

    public function money(): Money
    {
        return Money::make($this->amount, $this->currency);
    }

    public function commissionMoneyCad(): Money
    {
        return Money::make($this->commission_value_cad, 'CAD');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['unmatched', 'refunded', 'chargeback']);
    }

    public function scopePendingPostback(Builder $query, int $hours = 3): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours))
            ->whereIn('status', ['unmatched', 'pending']);
    }

    public function scopeInWindow(Builder $query, CarbonInterface|string $from, CarbonInterface|string|null $to = null): Builder
    {
        $fromDate = is_string($from) ? $from : $from->toDateTimeString();
        $query->where('created_at', '>=', $fromDate);

        if ($to !== null) {
            $toDate = is_string($to) ? $to : $to->toDateTimeString();
            $query->where('created_at', '<=', $toDate);
        }

        return $query;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fx_rate' => 'decimal:8',
            'commission_value_cad' => 'decimal:2',
            'uploaded_at' => 'datetime',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
