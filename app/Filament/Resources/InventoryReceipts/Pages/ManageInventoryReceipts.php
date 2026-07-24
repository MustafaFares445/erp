<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReceipts\Pages;

use App\Filament\Resources\InventoryReceipts\InventoryReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageInventoryReceipts extends ManageRecords
{
    protected static string $resource = InventoryReceiptResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
