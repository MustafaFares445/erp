<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolvedPriceSource: string
{
    case CustomerSpecificTier = 'customer_specific_tier';
    case ProductScopedTier = 'product_scoped_tier';
    case GeneralTier = 'general_tier';
    case Base = 'base';
}
