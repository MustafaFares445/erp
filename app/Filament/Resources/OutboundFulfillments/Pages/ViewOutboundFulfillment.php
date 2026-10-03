<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments\Pages;

use App\Filament\Resources\OutboundFulfillments\Actions\OutboundFulfillmentActions;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewOutboundFulfillment extends ViewRecord
{
    protected static string $resource = OutboundFulfillmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            OutboundFulfillmentActions::refreshAvailability(),
            OutboundFulfillmentActions::createDeliveryPlan(),
            OutboundFulfillmentActions::prepareDelivery(),
            OutboundFulfillmentActions::dispatchGoods(),
            OutboundFulfillmentActions::confirmArrival(),
        ];
    }
}
