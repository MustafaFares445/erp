<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case ComingSoon = 'coming_soon';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
