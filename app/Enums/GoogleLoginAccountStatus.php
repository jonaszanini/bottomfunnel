<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleLoginAccountStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Cancelled => 'Cancelled',
            self::Suspended => 'Suspended',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
            self::Suspended => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/20',
            self::Cancelled => 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 border border-zinc-500/20',
        };
    }
}
