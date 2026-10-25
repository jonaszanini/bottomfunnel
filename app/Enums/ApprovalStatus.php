<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Requires Approval',
            self::No => 'No Approval Required',
            self::Unknown => 'Approval Unknown',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Yes => 'Approval Required',
            self::No => 'Open / Instant',
            self::Unknown => 'Unknown',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Yes => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::No => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::Unknown => 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border-slate-500/30',
        };
    }

    public function isRequired(): bool
    {
        return $this === self::Yes;
    }
}
