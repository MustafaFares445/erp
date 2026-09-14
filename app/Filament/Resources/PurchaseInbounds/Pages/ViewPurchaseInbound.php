<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Filament\Resources\PurchaseInbounds\Actions\ReceiveGoodsAction;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseInbound extends ViewRecord
{
    protected static string $resource = PurchaseInboundResource::class;

    /** @return list<Action> */
    #[\Override]
    public function getHeaderActions(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof PurchaseInbound) {
            return [];
        }
        $warehouseIds = PurchaseInboundAllocation::query()
            ->whereHas(
                'purchaseInboundLine',
                static fn ($query) => $query->where('purchase_inbound_id', $record->id),
            )
            ->distinct()
            ->orderBy('warehouse_id')
            ->pluck('warehouse_id');

        $actions = [];
        $receiving = app(PurchaseOrderReceivingService::class);

        foreach (Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('name')->get() as $warehouse) {
            $hasAvailable = PurchaseInboundAllocation::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereHas(
                    'purchaseInboundLine',
                    static fn ($query) => $query->where('purchase_inbound_id', $record->id),
                )
                ->get()
                ->contains(static fn (PurchaseInboundAllocation $allocation): bool =>
                    bccomp($receiving->availableBaseQuantityForAllocation($allocation), '0.000000', 6) === 1
                );

            if ($hasAvailable) {
                $actions[] = ReceiveGoodsAction::make($record, $warehouse);
            }
        }

        return $actions;
    }
}
