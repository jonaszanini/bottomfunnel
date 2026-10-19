<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleAdsAccount extends Model
{
    protected $fillable = [
        'customer_id',
        'login_customer_id',
        'refresh_token',
        'name',
        'status',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }

    public function conversionActions(): HasMany
    {
        return $this->hasMany(ConversionAction::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }
}
