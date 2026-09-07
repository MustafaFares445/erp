<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            OrderActions::prepareFulfillment(),
            OrderActions::detectProcurement(),
            OrderActions::requestSupplierConfirmation(),
            OrderActions::createPurchaseOrder(),
            EditAction::make(),
        ];
    }
}
