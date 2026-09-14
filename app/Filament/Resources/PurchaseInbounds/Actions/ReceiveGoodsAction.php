<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Actions;

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class ReceiveGoodsAction
{
    public static function make(PurchaseInbound $inbound, Warehouse $warehouse): Action
    {
        return Action::make('receiveGoodsWarehouse'.$warehouse->id)
            ->label(__('admin.logistics.actions.receive_goods').' — '.$warehouse->name)
            ->icon('heroicon-o-inbox-arrow-down')
            ->visible(fn (): bool => auth()->user()?->can(InventoryPermission::ReceiptCreate->value) ?? false)
            ->schema([
                Placeholder::make('supplier')
                    ->content($inbound->purchaseOrder->supplier->name),
                Placeholder::make('po_reference')
                    ->content($inbound->purchaseOrder->purchase_order_number),
                Placeholder::make('warehouse')
                    ->content($warehouse->name),
                Repeater::make('lines')
                    ->label(__('admin.logistics.receipt.quantity_summary'))
                    ->default(self::receiptDefaults($inbound, $warehouse))
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->schema([
                        Hidden::make('purchase_inbound_allocation_id'),
                        TextInput::make('product')->label(__('admin.logistics.inbound.product'))->disabled()->dehydrated(false),
                        TextInput::make('allocated')->label(__('admin.logistics.inbound.allocated'))->disabled()->dehydrated(false),
                        TextInput::make('already_received')->label(__('admin.logistics.receipt.already_received'))->disabled()->dehydrated(false),
                        TextInput::make('open_receipt')->label(__('admin.logistics.receipt.open_receipt'))->disabled()->dehydrated(false),
                        TextInput::make('available')->label(__('admin.logistics.receipt.available'))->disabled()->dehydrated(false),
                        TextInput::make('quantity')
                            ->label(__('admin.logistics.receipt.actual_quantity'))
                            ->numeric()->step(0.000001)->minValue(0.000001)->required(),
                    ])
                    ->columns(6),
            ])
            ->action(function (array $data) use ($inbound): void {
                $lines = [];

                foreach (($data['lines'] ?? []) as $line) {
                    if (! is_array($line) || ! isset($line['purchase_inbound_allocation_id'], $line['quantity'])) {
                        continue;
                    }

                    $lines[] = [
                        'purchase_inbound_allocation_id' => (int) $line['purchase_inbound_allocation_id'],
                        'quantity' => (string) $line['quantity'],
                    ];
                }

                $operation = app(PurchaseOrderReceivingService::class)->initiate(
                    self::actor(),
                    $inbound->purchaseOrder,
                    $lines,
                );

                Notification::make()
                    ->success()
                    ->title(__('admin.logistics.notifications.draft_receipt_created'))
                    ->body(__('admin.logistics.notifications.complete_draft_receipt'))
                    ->send();

                redirect(InventoryOperationResource::getUrl('edit', ['record' => $operation]));
            });
    }

    /** @return list<array<string, mixed>> */
    private static function receiptDefaults(PurchaseInbound $inbound, Warehouse $warehouse): array
    {
        $rows = [];
        $service = app(PurchaseOrderReceivingService::class);

        $allocations = PurchaseInboundAllocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereHas('purchaseInboundLine', static fn (Builder $query): Builder => $query->where('purchase_inbound_id', $inbound->id))
            ->with('purchaseInboundLine.purchaseOrderLine.productVariant.product')
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            $available = $service->availableBaseQuantityForAllocation($allocation);

            if (bccomp($available, '0.000000', 6) !== 1) {
                continue;
            }

            $received = $allocation->receivedBaseQuantity();
            $allocated = $allocation->allocated_base_quantity ?? '0.000000';
            $open = bcsub(bcsub($allocated, $received, 6), $available, 6);
            $poLine = $allocation->purchaseInboundLine->purchaseOrderLine;
            $variant = $poLine->productVariant;

            $rows[] = [
                'purchase_inbound_allocation_id' => $allocation->id,
                'product' => ($variant->product?->name ?? $variant->name).' ('.$variant->sku.')',
                'allocated' => $allocated,
                'already_received' => $received,
                'open_receipt' => bccomp($open, '0.000000', 6) === -1 ? '0.000000' : $open,
                'available' => $available,
                'quantity' => $available,
            ];
        }

        return $rows;
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated warehouse actor is required.');
        }

        return $actor;
    }
}
