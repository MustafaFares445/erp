<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Resources\PriceLists\PriceListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePriceLists extends ManageRecords
{
    protected static string $resource = PriceListResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => PriceListResource::canManagePricing()),
        ];
    }

    #[\Override]
    public function getSubheading(): string
    {
        return __('Customer and customer-group pricing with optional quantity breaks.');
    }
}
