<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Filament\Resources\PurchaseInbounds\Actions\PurchaseInboundActions;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseInbound;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewPurchaseInbound extends ViewRecord
{
    protected static string $resource = PurchaseInboundResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            PurchaseInboundActions::allocate(),
            PurchaseInboundActions::createOrOpenReceipt(),
            Action::make('purchaseOrder')
                ->label(__('admin.logistics.inbound.allocation.open_purchase_order'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (PurchaseInbound $record): string => PurchaseOrderResource::getUrl('view', [
                    'record' => $record->purchaseOrder,
                ])),
        ];
    }
}
