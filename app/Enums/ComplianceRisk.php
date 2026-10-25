<?php

namespace App\Enums;

enum ComplianceRisk: string
{
    case NotAssessed = 'not_assessed';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::NotAssessed => 'Not Assessed',
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
            self::NotAssessed => 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border-slate-500/30',
            self::Low => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::Medium => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::High => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
            self::Restricted => 'bg-rose-600/20 text-rose-700 dark:text-rose-300 border-rose-600/40 font-bold',
        };
    }
}
