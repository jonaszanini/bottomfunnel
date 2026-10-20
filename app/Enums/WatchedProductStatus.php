<?php

namespace App\Enums;

enum WatchedProductStatus: string
{
    case Test = 'test';
    case Watch = 'watch';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test',
            self::Watch => 'Watch',
            self::Rejected => 'Rejected',
        };
    }
}
