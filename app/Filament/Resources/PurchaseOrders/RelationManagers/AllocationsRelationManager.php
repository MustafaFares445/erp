<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\InventoryPermission;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Exposes Inventory-owned purchase inbound allocation from the Purchase Order.
 *
 * A PO line can be split across many warehouses. This relation manager therefore
 * renders line-level totals plus the child warehouse allocations and delegates
 * all mutations to PurchaseInboundService. No inventory posting or financial
 * behavior lives in this Filament layer.
 */
final class AllocationsRelationManager extends RelationManager
{
    use InteractsWithPurchasingServices;

    private const int QUANTITY_SCALE = 6;

    protected static string $relationship = 'inboundLines';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.purchasing.fields.allocations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('purchaseOrderLine.productVariant.sku')
                    ->label(__('admin.purchasing.fields.product_variant')),
                TextColumn::make('purchaseOrderLine.base_quantity')
                    ->label(__('purchase_inbound.fields.ordered_base_quantity'))
                    ->placeholder('—'),
                TextColumn::make('allocated_total')
                    ->label(__('purchase_inbound.fields.allocated_total'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => $record->allocatedBaseQuantity()),
                TextColumn::make('unallocated_total')
                    ->label(__('purchase_inbound.fields.unallocated_base_quantity'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => $record->unallocatedBaseQuantity() ?? '—'),
                TextColumn::make('received_total')
                    ->label(__('purchase_inbound.fields.received_total'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::receivedForLine($record)),
                TextColumn::make('remaining_total')
                    ->label(__('purchase_inbound.fields.remaining_total'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::remainingForLine($record)),
                TextColumn::make('allocation_warehouses')
                    ->label(__('purchase_inbound.fields.warehouse'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): array => self::allocationColumn(
                        $record,
                        static fn (PurchaseInboundAllocation $allocation): string => $allocation->warehouse->name,
                    ))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                TextColumn::make('allocation_quantities')
                    ->label(__('purchase_inbound.fields.allocation_allocated'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): array => self::allocationColumn(
                        $record,
                        static fn (PurchaseInboundAllocation $allocation): string => $allocation->allocated_base_quantity ?? '—',
                    ))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                TextColumn::make('allocation_received')
                    ->label(__('purchase_inbound.fields.allocation_received'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): array => self::allocationColumn(
                        $record,
                        static fn (PurchaseInboundAllocation $allocation): string => $allocation->receivedBaseQuantity(),
                    ))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                TextColumn::make('allocation_remaining')
                    ->label(__('purchase_inbound.fields.allocation_remaining'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): array => self::allocationColumn(
                        $record,
                        static fn (PurchaseInboundAllocation $allocation): string => $allocation->remainingBaseQuantity() ?? '—',
                    ))
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('addAllocation')
                    ->label(__('purchase_inbound.actions.add_allocation'))
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('purchase_inbound.fields.warehouse'))
                            ->options(fn (PurchaseInboundLine $record): array => self::availableWarehouseOptions($record))
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('allocated_base_quantity')
                            ->label(__('purchase_inbound.fields.allocated_base_quantity'))
                            ->helperText(__('purchase_inbound.hints.base_quantity'))
                            ->numeric()
                            ->step(0.000001)
                            ->minValue(0.000001)
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?string => $record->unallocatedBaseQuantity()),
                    ])
                    ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate()
                        && self::hasUnallocatedQuantity($record))
                    ->action(function (PurchaseInboundLine $record, array $data): void {
                        $actor = self::requireActor();
                        /** @var Warehouse $warehouse */
                        $warehouse = Warehouse::query()->findOrFail(self::integerFrom($data['warehouse_id'] ?? null));

                        self::runPurchasingOperation(
                            fn (): PurchaseInboundAllocation => app(PurchaseInboundService::class)->allocate(
                                $actor,
                                $record,
                                $warehouse,
                                self::stringFrom($data['allocated_base_quantity'] ?? null),
                            ),
                            'purchase_inbound.notifications.allocation_created',
                        );
                    }),
                Action::make('editAllocation')
                    ->label(__('purchase_inbound.actions.edit_allocation'))
                    ->schema([
                        Select::make('allocation_id')
                            ->label(__('purchase_inbound.fields.allocation'))
                            ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?int => self::singleAllocationId($record)),
                        Select::make('warehouse_id')
                            ->label(__('purchase_inbound.fields.warehouse'))
                            ->options(fn (): array => self::activeWarehouseOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?int => self::singleAllocation($record)?->warehouse_id),
                        TextInput::make('allocated_base_quantity')
                            ->label(__('purchase_inbound.fields.allocated_base_quantity'))
                            ->helperText(__('purchase_inbound.hints.edit_allocation'))
                            ->numeric()
                            ->step(0.000001)
                            ->minValue(0.000001)
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?string => self::singleAllocation($record)?->allocated_base_quantity),
                    ])
                    ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
                    ->action(function (PurchaseInboundLine $record, array $data): void {
                        $actor = self::requireActor();
                        /** @var PurchaseInboundAllocation $allocation */
                        $allocation = $record->allocations()->findOrFail(self::integerFrom($data['allocation_id'] ?? null));
                        /** @var Warehouse $warehouse */
                        $warehouse = Warehouse::query()->findOrFail(self::integerFrom($data['warehouse_id'] ?? null));

                        self::runPurchasingOperation(
                            fn (): PurchaseInboundAllocation => app(PurchaseInboundService::class)->updateAllocation(
                                $actor,
                                $record,
                                $allocation,
                                $warehouse,
                                self::stringFrom($data['allocated_base_quantity'] ?? null),
                            ),
                            'purchase_inbound.notifications.allocation_updated',
                        );
                    }),
                Action::make('removeAllocation')
                    ->label(__('purchase_inbound.actions.remove_allocation'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('purchase_inbound.hints.remove_allocation'))
                    ->schema([
                        Select::make('allocation_id')
                            ->label(__('purchase_inbound.fields.allocation'))
                            ->options(fn (PurchaseInboundLine $record): array => self::allocationOptions($record))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?int => self::singleAllocationId($record)),
                    ])
                    ->visible(fn (PurchaseInboundLine $record): bool => self::canAllocate() && $record->allocations()->exists())
                    ->action(function (PurchaseInboundLine $record, array $data): void {
                        $actor = self::requireActor();
                        /** @var PurchaseInboundAllocation $allocation */
                        $allocation = $record->allocations()->findOrFail(self::integerFrom($data['allocation_id'] ?? null));

                        self::runPurchasingOperation(
                            function () use ($actor, $record, $allocation): void {
                                app(PurchaseInboundService::class)->removeAllocation($actor, $record, $allocation);
                            },
                            'purchase_inbound.notifications.allocation_removed',
                        );
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function canAllocate(): bool
    {
        return self::purchasingActor()?->can(InventoryPermission::InboundAllocate->value) ?? false;
    }

    private static function requireActor(): User
    {
        $actor = self::purchasingActor();

        if (! $actor instanceof User) {
            throw new LogicException('An allocation cannot be changed without an authenticated actor.');
        }

        return $actor;
    }

    private static function hasUnallocatedQuantity(PurchaseInboundLine $line): bool
    {
        $unallocated = $line->unallocatedBaseQuantity();

        return $unallocated !== null && bccomp($unallocated, '0.000000', self::QUANTITY_SCALE) === 1;
    }

    /** @return array<int|string, string> */
    private static function availableWarehouseOptions(PurchaseInboundLine $line): array
    {
        $usedWarehouseIds = $line->allocations()->pluck('warehouse_id');

        $query = Warehouse::query()->where('is_active', true);

        if ($usedWarehouseIds->isNotEmpty()) {
            $query->whereNotIn('id', $usedWarehouseIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Warehouse $warehouse): array => [$warehouse->id => $warehouse->name])
            ->all();
    }

    /** @return array<int|string, string> */
    private static function activeWarehouseOptions(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Warehouse $warehouse): array => [$warehouse->id => $warehouse->name])
            ->all();
    }

    /** @return array<int, string> */
    private static function allocationOptions(PurchaseInboundLine $line): array
    {
        $options = [];

        foreach (self::allocations($line) as $allocation) {
            $options[$allocation->id] = __('purchase_inbound.options.allocation', [
                'warehouse' => $allocation->warehouse->name,
                'allocated' => $allocation->allocated_base_quantity ?? '—',
                'received' => $allocation->receivedBaseQuantity(),
                'remaining' => $allocation->remainingBaseQuantity() ?? '—',
            ]);
        }

        return $options;
    }

    private static function singleAllocationId(PurchaseInboundLine $line): ?int
    {
        $allocation = self::singleAllocation($line);

        return $allocation instanceof PurchaseInboundAllocation
            ? $allocation->id
            : null;
    }

    private static function singleAllocation(PurchaseInboundLine $line): ?PurchaseInboundAllocation
    {
        $allocations = self::allocations($line);

        return $allocations->count() === 1 ? $allocations->first() : null;
    }

    /**
     * @param  callable(PurchaseInboundAllocation): string  $mapper
     * @return list<string>
     */
    private static function allocationColumn(PurchaseInboundLine $line, callable $mapper): array
    {
        return array_values(self::allocations($line)
            ->map($mapper)
            ->values()
            ->all());
    }

    /** @return Collection<int, PurchaseInboundAllocation> */
    private static function allocations(PurchaseInboundLine $line): Collection
    {
        return $line->allocations()
            ->with('warehouse')
            ->orderBy('id')
            ->get();
    }

    private static function receivedForLine(PurchaseInboundLine $line): string
    {
        $received = $line->purchaseOrderLine()->value('received_base_quantity');

        if ($received !== null) {
            if (is_int($received) || is_float($received) || (is_string($received) && is_numeric($received))) {
                return bcadd('0.000000', (string) $received, self::QUANTITY_SCALE);
            }

            return '0.000000';
        }

        $total = '0.000000';

        foreach (self::allocations($line) as $allocation) {
            $total = bcadd($total, $allocation->receivedBaseQuantity(), self::QUANTITY_SCALE);
        }

        return $total;
    }

    private static function remainingForLine(PurchaseInboundLine $line): string
    {
        $ordered = $line->inboundBaseQuantity();

        if ($ordered === null) {
            return '—';
        }

        $remaining = bcsub($ordered, self::receivedForLine($line), self::QUANTITY_SCALE);

        return bccomp($remaining, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $remaining;
    }
}
