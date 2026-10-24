<?php

namespace App\Models;

use App\Services\WatchingProducts\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

    public function money(): Money
    {
        return Money::make((float) $this->amount_cad, 'CAD');
    }

    public function scopeForTrack(Builder $query, int $trackId): Builder
    {
        return $query->where('track_id', $trackId);
    }

    public function scopeInWindow(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): Builder
    {
        $fromDate = is_string($from) ? $from : $from->toDateString();
        $toDate = is_string($to) ? $to : $to->toDateString();

        return $query->whereDate('start_date', '<=', $toDate)
            ->whereDate('end_date', '>=', $fromDate);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
