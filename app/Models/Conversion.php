<?php

namespace App\Models;

use App\Services\WatchingProducts\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversion extends Model
{
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
