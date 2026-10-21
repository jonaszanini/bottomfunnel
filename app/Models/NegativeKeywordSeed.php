<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NegativeKeywordSeed extends Model
{
    use HasFactory;

    protected $table = 'negative_keyword_seeds';

    protected $fillable = [
        'term',
        'enabled_by_default',
        'strategy_rules',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled_by_default' => 'boolean',
            'strategy_rules' => 'array',
        ];
    }
}
