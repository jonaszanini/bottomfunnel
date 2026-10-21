<?php

namespace App\Enums;

enum ProductKind: string
{
    case Nutra = 'nutra';
    case Ecom = 'ecom';

    public function label(): string
    {
        return match ($this) {
            self::Nutra => 'Nutra',
            self::Ecom => 'E-com',
        };
    }
}
