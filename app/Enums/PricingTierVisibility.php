<?php

declare(strict_types=1);

namespace App\Enums;

enum PricingTierVisibility: string
{
    case Public = 'public';
    case Restricted = 'restricted';
}
