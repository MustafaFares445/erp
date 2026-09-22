<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Pages;

use App\Filament\Resources\CustomerReturnRequests\CustomerReturnRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCustomerReturnRequests extends ListRecords
{
    protected static string $resource = CustomerReturnRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Submit on behalf of customer'),
        ];
    }
}
