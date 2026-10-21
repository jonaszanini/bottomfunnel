<?php

namespace App\Enums;

enum ListingStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Unknown => 'Unknown',
        };
    }
}
