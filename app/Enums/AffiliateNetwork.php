<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateNetwork: string
{
    case Clickbank = 'clickbank';
    case Digistore24 = 'digistore24';
    case Buygoods = 'buygoods';
    case Maxweb = 'maxweb';
    case Hotmart = 'hotmart';
    case Warriorplus = 'warriorplus';
    case Jvzoo = 'jvzoo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Clickbank => 'ClickBank',
            self::Digistore24 => 'Digistore24',
            self::Buygoods => 'BuyGoods',
            self::Maxweb => 'MaxWeb',
            self::Hotmart => 'Hotmart',
            self::Warriorplus => 'WarriorPlus',
            self::Jvzoo => 'JVZoo',
            self::Other => 'Other',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Clickbank => 'bg-red-500/10 text-red-600 border-red-500/20 dark:text-red-400',
            self::Digistore24 => 'bg-blue-500/10 text-blue-600 border-blue-500/20 dark:text-blue-400',
            self::Buygoods => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400',
            self::Maxweb => 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400',
            self::Hotmart => 'bg-orange-500/10 text-orange-600 border-orange-500/20 dark:text-orange-400',
            self::Warriorplus => 'bg-purple-500/10 text-purple-600 border-purple-500/20 dark:text-purple-400',
            self::Jvzoo => 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20 dark:text-indigo-400',
            self::Other => 'bg-zinc-500/10 text-zinc-600 border-zinc-500/20 dark:text-zinc-400',
        };
    }
}
