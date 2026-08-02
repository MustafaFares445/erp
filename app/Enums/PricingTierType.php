<?php

declare(strict_types=1);

namespace App\Enums;

enum PricingTierType: string
{
    case General = 'general';
    case CustomerSpecific = 'customer_specific';
    case ProductScoped = 'product_scoped';
}
