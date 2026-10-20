<?php

namespace App\Enums;

enum TrueBofStatus: string
{
    case True = 'true';
    case False = 'false';
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::True => 'True BoF',
            self::False => 'Non-BoF',
            self::Ambiguous => 'Ambiguous',
        };
    }
}
