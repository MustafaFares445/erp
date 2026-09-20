<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerQuotationRequests\Pages;

use App\Filament\Resources\CustomerQuotationRequests\Actions\CustomerQuotationRequestActions;
use App\Filament\Resources\CustomerQuotationRequests\CustomerQuotationRequestResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCustomerQuotationRequest extends ViewRecord
{
    protected static string $resource = CustomerQuotationRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CustomerQuotationRequestActions::startReview(),
            CustomerQuotationRequestActions::convert(),
            CustomerQuotationRequestActions::reject(),
        ];
    }
}
