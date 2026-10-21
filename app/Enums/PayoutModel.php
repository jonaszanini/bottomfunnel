<?php

namespace App\Enums;

enum PayoutModel: string
{
    case CpaFixed = 'cpa_fixed';
    case Revshare = 'revshare';

    public function label(): string
    {
        return match ($this) {
            self::CpaFixed => 'CPA Fixed',
            self::Revshare => 'RevShare',
        };
    }
}
