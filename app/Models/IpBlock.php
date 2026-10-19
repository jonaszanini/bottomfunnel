<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpBlock extends Model
{
    protected $fillable = [
        'track_id',
        'google_ads_account_id',
        'ip_address',
        'reason',
        'campaign_id',
        'criterion_resource_name',
        'status',
        'last_error',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }
}
