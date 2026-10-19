<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    protected $fillable = [
        'rate_date',
        'currency',
        'cad_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'cad_per_unit' => 'decimal:8',
        ];
    }
}
