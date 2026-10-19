<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionAction extends Model
{
    protected $fillable = [
        'google_ads_account_id',
        'resource_name',
        'external_id',
        'name',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }
}
