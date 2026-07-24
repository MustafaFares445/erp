<?php

declare(strict_types=1);

namespace App\Filament\Resources\PricingTiers\Pages;

use App\Filament\Resources\PricingTiers\PricingTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePricingTiers extends ManageRecords
{
    protected static string $resource = PricingTierResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
