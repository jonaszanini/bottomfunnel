<?php

namespace App\Enums;

enum NegativeKeywordMatchType: string
{
    case Broad = 'broad';
    case Phrase = 'phrase';
    case Exact = 'exact';

    public function label(): string
    {
        return match ($this) {
            self::Broad => 'Broad',
            self::Phrase => 'Phrase',
            self::Exact => 'Exact',
        };
    }
}
