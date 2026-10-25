<?php

namespace App\Enums;

enum ComplianceRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low Risk',
            self::Medium => 'Medium Risk',
            self::High => 'High Risk',
            self::Restricted => 'Restricted',
        };
    }

    public function isRestricted(): bool
    {
        return $this === self::Restricted;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Low => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::Medium => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::High => 'bg-orange-500/15 text-orange-600 dark:text-orange-400 border-orange-500/30',
            self::Restricted => 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border-rose-500/30 font-bold',
        };
    }
}
