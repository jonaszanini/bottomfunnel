<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected $fillable = [
        'session_id',
        'track_id',
        'page_id',
        'page_type',
        'gclid',
        'gbraid',
        'wbraid',
        'ip_address',
        'user_agent',
        'referrer',
        'landed_at',
        'time_on_page_seconds',
        'engagement',
        'fraud_score',
        'fraud_reason',
        'flagged',
        'auto_blocked',
        'converted',
        'last_ping_at',
    ];

    protected function casts(): array
    {
        return [
            'landed_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'engagement' => 'array',
            'flagged' => 'boolean',
            'auto_blocked' => 'boolean',
            'converted' => 'boolean',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }
}
