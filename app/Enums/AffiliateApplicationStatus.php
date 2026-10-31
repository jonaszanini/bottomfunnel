<?php

namespace App\Enums;

enum AffiliateApplicationStatus: string
{
    case NotRequested = 'not_requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequested => 'Não solicitado',
            self::Pending => 'Pendente',
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotRequested => 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border-slate-500/30',
            self::Pending => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::Approved => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::Rejected => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
        };
    }
}
