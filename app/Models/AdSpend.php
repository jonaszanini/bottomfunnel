<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSpend extends Model
{
    protected $table = 'ad_spend';

    protected $fillable = [
        'track_id',
        'start_date',
        'end_date',
        'amount_cad',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'amount_cad' => 'decimal:2',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
