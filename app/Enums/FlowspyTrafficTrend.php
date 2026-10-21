<?php

namespace App\Enums;

enum FlowspyTrafficTrend: string
{
    case Rising = 'rising';
    case Stable = 'stable';
    case Falling = 'falling';

    public function label(): string
    {
        return match ($this) {
            self::Rising => 'Rising',
            self::Stable => 'Stable',
            self::Falling => 'Falling',
        };
    }
}
