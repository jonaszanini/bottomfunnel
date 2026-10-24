<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleAdsCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'google_ads_account_id',
        'customer_id',
        'campaign_id',
        'name',
        'status',
        'budget_amount_micros',
        'bidding_strategy',
        'resource_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_amount_micros' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'campaign_id', 'campaign_id');
    }
}
