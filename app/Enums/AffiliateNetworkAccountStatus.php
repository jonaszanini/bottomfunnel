<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateNetworkAccountStatus: string
{
    case Active = 'active';
    case PendingApproval = 'pending_approval';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PendingApproval => 'Pending Approval',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400',
            self::PendingApproval => 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400',
            self::Suspended => 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400',
            self::Closed => 'bg-rose-500/10 text-rose-600 border-rose-500/20 dark:text-rose-400',
        };
    }
}
