<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class ViewPurchaseInbound extends ViewRecord
{
    protected static string $resource = PurchaseInboundResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('allocateInbound')
                ->label(__('admin.logistics.inbound.allocation.allocate'))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->color('primary')
                ->visible(fn (PurchaseInbound $record): bool => (auth()->user()?->can(InventoryPermission::InboundAllocate->value) ?? false)
                    && self::allocatableLineOptions($record) !== [])
                ->schema([
                    Select::make('purchase_inbound_line_id')
                        ->label(__('admin.logistics.inbound.allocation.inbound_line'))
                        ->options(fn (PurchaseInbound $record): array => self::allocatableLineOptions($record))
                        ->searchable()
                        ->required(),
                    Select::make('warehouse_id')
                        ->label(__('admin.logistics.inbound.allocation.warehouse'))
                        ->options(fn (): array => Warehouse::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('allocated_base_quantity')
                        ->label(__('admin.logistics.inbound.allocation.quantity_to_allocate'))
                        ->numeric()
                        ->minValue(0.000001)
                        ->step(0.000001)
                        ->required(),
                ])
                ->action(function (PurchaseInbound $record, array $data): void {
                    $line = $record->lines()->findOrFail(self::integerInput($data['purchase_inbound_line_id'] ?? null));
                    $warehouse = Warehouse::query()->findOrFail(self::integerInput($data['warehouse_id'] ?? null));

                    app(PurchaseInboundService::class)->allocate(
                        self::actor(),
                        $line,
                        $warehouse,
                        self::quantityInput($data['allocated_base_quantity'] ?? null),
                    );

                    Notification::make()->success()->title(__('admin.logistics.inbound.notifications.allocated'))->send();
                }),
            Action::make('createOrOpenReceipt')
                ->label(__('admin.logistics.inbound.allocation.create_open_receipt'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('success')
                ->visible(fn (PurchaseInbound $record): bool => (auth()->user()?->can(InventoryPermission::ReceiptCreate->value) ?? false)
                    && self::receivableAllocationOptions($record) !== [])
                ->schema([
                    Select::make('allocation_id')
                        ->label(__('admin.logistics.inbound.allocation.allocation'))
                        ->options(fn (PurchaseInbound $record): array => self::receivableAllocationOptions($record))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (PurchaseInbound $record, array $data): void {
                    $allocation = PurchaseInboundAllocation::query()
                        ->whereKey(self::integerInput($data['allocation_id'] ?? null))
                        ->whereHas('purchaseInboundLine', static fn (Builder $query): Builder => $query->where('purchase_inbound_id', $record->id))
                        ->firstOrFail();

                    $receipt = app(PurchaseOrderReceivingService::class)
                        ->ensureDraftReceiptForAllocation(self::actor(), $allocation);

                    Notification::make()
                        ->success()
                        ->title(__('admin.logistics.inbound.notifications.receipt_ready', [
                            'number' => $receipt->operation_number,
                        ]))
                        ->send();

                    $this->redirect(InventoryOperationResource::getUrl('view', ['record' => $receipt]));
                }),
            Action::make('purchaseOrder')
                ->label(__('admin.logistics.inbound.allocation.open_purchase_order'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (PurchaseInbound $record): string => PurchaseOrderResource::getUrl('view', [
                    'record' => $record->purchaseOrder,
                ])),
        ];
    }

    /** @return array<int, string> */
    private static function allocatableLineOptions(PurchaseInbound $inbound): array
    {
        $options = [];

        foreach ($inbound->lines()->with('purchaseOrderLine.productVariant.product')->orderBy('id')->get() as $line) {
            $projection = app(LogisticsInboundProjectionService::class)->projectLine($line);

            if (! self::isPositiveQuantity($projection->currentlyAllocatableBaseQuantity)) {
                continue;
            }

            $options[$line->id] = __('admin.logistics.inbound.phrases.allocatable', [
                'sku' => $projection->sku,
                'product' => $projection->product,
                'quantity' => $projection->currentlyAllocatableBaseQuantity,
            ]);
        }

        return $options;
    }

    /** @return array<int, string> */
    private static function receivableAllocationOptions(PurchaseInbound $inbound): array
    {
        $options = [];
        $allocations = PurchaseInboundAllocation::query()
            ->whereHas('purchaseInboundLine', static fn (Builder $query): Builder => $query->where('purchase_inbound_id', $inbound->id))
            ->with(['warehouse', 'purchaseInboundLine.purchaseOrderLine.productVariant'])
            ->orderBy('id')
            ->get();

        foreach ($allocations as $allocation) {
            $remaining = $allocation->remainingBaseQuantity();
            if ($remaining === null) {
                continue;
            }
            if (bccomp($remaining, '0.000000', 6) !== 1) {
                continue;
            }

            $sku = $allocation->purchaseInboundLine->purchaseOrderLine->productVariant->sku;
            $options[$allocation->id] = __('admin.logistics.inbound.phrases.remaining', [
                'warehouse' => $allocation->warehouse->name,
                'sku' => $sku,
                'quantity' => $remaining,
            ]);
        }

        return $options;
    }

    private static function integerInput(mixed $value): int
    {
        if (! is_numeric($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new LogicException('An integer workflow identifier is required.');
        }

        return (int) $value;
    }

    /** @return numeric-string */
    private static function quantityInput(mixed $value): string
    {
        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)) {
            throw new LogicException('A numeric inbound quantity is required.');
        }

        /** @var numeric-string $quantity */
        $quantity = (string) $value;

        return $quantity;
    }

    private static function isPositiveQuantity(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0.000001;
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated Inventory user is required.');
        }

        return $actor;
    }
}
