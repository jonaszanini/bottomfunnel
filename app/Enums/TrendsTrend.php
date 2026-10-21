<?php

namespace App\Enums;

enum TrendsTrend: string
{
    case Rising = 'rising';
    case Stable = 'stable';
    case Falling = 'falling';
    case DeadPostPeak = 'dead_post_peak';

    public function label(): string
    {
        return match ($this) {
            self::Rising => 'Rising',
            self::Stable => 'Stable',
            self::Falling => 'Falling',
            self::DeadPostPeak => 'Dead Post Peak',
        };
    }
}
