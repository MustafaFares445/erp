<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Pages;

use App\Filament\Resources\CustomerQuotationRequests\CustomerQuotationRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCustomerQuotationRequests extends ListRecords
{
    protected static string $resource = CustomerQuotationRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Create on behalf of customer'),
        ];
    }
}
