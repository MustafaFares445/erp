<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments\Pages;

use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListOutboundFulfillments extends ListRecords
{
    protected static string $resource = OutboundFulfillmentResource::class;
}
