<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'track_id',
        'campaign_id',
        'name',
        'page_type',
        'status',
        'error_message',
        'language',
        'structure_json',
        'html',
        'injected_script',
    ];

    protected function casts(): array
    {
        return [
            'structure_json' => 'array',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsCampaign::class, 'campaign_id', 'campaign_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' || $this->status === null;
    }

    public function failureReason(): ?string
    {
        return $this->error_message ?? ($this->structure_json['error'] ?? null);
    }
}
