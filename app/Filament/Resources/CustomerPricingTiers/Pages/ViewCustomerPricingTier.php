<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerPricingTiers\Pages;

use App\Filament\Resources\CustomerPricingTiers\CustomerPricingTierResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCustomerPricingTier extends ViewRecord
{
    protected static string $resource = CustomerPricingTierResource::class;
}
