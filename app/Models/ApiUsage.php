<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiUsage extends Model
{
    use HasFactory;

    protected $table = 'api_usage';

    protected $fillable = [
        'provider',
        'period',
        'calls',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calls' => 'integer',
        ];
    }

    public static function forPeriod(string $provider, string $period): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('period', $period)
            ->first();
    }
}
