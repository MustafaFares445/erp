<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolvedPriceSource: string
{
    case CustomerPriceList = 'customer_price_list';
    case CustomerSpecificTier = 'customer_specific_tier';
    case ProductScopedTier = 'product_scoped_tier';
    case GeneralTier = 'general_tier';
    case Base = 'base';
    case ManualOverride = 'manual_override';

    public function label(): string
    {
        return __('admin.sales.price_source.'.$this->value);
    }
}
