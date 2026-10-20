<?php

namespace App\Enums;

enum BrandBiddingStatus: string
{
    case Allowed = 'allowed';
    case Banned = 'banned';
    case Unknown = 'unknown';
    case ConfirmWithAm = 'confirm_with_am';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => 'Allowed',
            self::Banned => 'Banned',
            self::Unknown => 'Unknown',
            self::ConfirmWithAm => 'Confirm with AM',
        };
    }
}
