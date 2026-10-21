<?php

namespace App\Enums;

enum StrategySource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::Manual => 'Manual',
        };
    }
}
