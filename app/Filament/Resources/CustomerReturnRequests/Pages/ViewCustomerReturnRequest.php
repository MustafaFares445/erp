<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Pages;

use App\Filament\Resources\CustomerReturnRequests\Actions\CustomerReturnRequestActions;
use App\Filament\Resources\CustomerReturnRequests\CustomerReturnRequestResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCustomerReturnRequest extends ViewRecord
{
    protected static string $resource = CustomerReturnRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CustomerReturnRequestActions::startReview(),
            CustomerReturnRequestActions::approveAndConvert(),
            CustomerReturnRequestActions::retryConvert(),
            CustomerReturnRequestActions::reject(),
        ];
    }
}
