<?php

declare(strict_types=1);

namespace App\Filament\Resources\PricingTiers\Pages;

use App\Filament\Resources\PricingTiers\PricingTierResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

final class ManagePricingTiers extends ManageRecords
{
    protected static string $resource = PricingTierResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('Calendar'))->icon('heroicon-o-calendar-days')->url(PricingTierResource::getUrl('calendar')),
            PricingTierResource::createAction(),
            PricingTierResource::assignGeneralTierAction(),
        ];
    }

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.pricing.tier_list_notice');
    }
}
