<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Pages;

use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\SupplierConfirmation;
use Filament\Resources\Pages\ViewRecord;

final class ViewSupplierConfirmation extends ViewRecord
{
    protected static string $resource = SupplierConfirmationResource::class;

    #[\Override]
    public function getTitle(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof SupplierConfirmation) {
            return 'Supplier Confirmation';
        }

        return 'Supplier Confirmation · '.$record->purchaseOrder->purchase_order_number;
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [SupplierConfirmationActions::response()];
    }
}
