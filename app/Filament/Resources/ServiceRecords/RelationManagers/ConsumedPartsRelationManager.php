<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\RelationManagers;

use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Inventory\InventoryLotService;
use App\Services\Support\ServiceRecordPartService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ConsumedPartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.name')->label(__('Product variant')),
                TextColumn::make('warehouse.name')->label(__('Warehouse')),
                TextColumn::make('lot.lot_number')->label(__('Lot'))->placeholder(__('—')),
                TextColumn::make('serializedUnit.serial_number')->label(__('Serial'))->placeholder(__('—')),
                TextColumn::make('quantity')->numeric(6),
                TextColumn::make('createdBy.name')->label(__('Consumed by')),
                TextColumn::make('created_at')->label(__('Consumed at'))->dateTime(),
                TextColumn::make('reversed_at')->label(__('Reversed at'))->dateTime()->placeholder(__('—')),
            ])
            ->headerActions([
                Action::make('consumePart')
                    ->label(__('Consume Part'))
                    ->schema([
                        Select::make('product_variant_id')
                            ->label(__('Product variant'))
                            ->relationship('productVariant', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('warehouse_id')
                            ->label(__('Warehouse'))
                            ->relationship('warehouse', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('inventory_lot_id')
                            ->label(__('Lot'))
                            ->options(fn (Get $get): array => self::lotOptions($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => self::tracksBatches($get('product_variant_id')))
                            ->required(fn (Get $get): bool => self::tracksBatches($get('product_variant_id'))),
                        Select::make('serialized_inventory_unit_id')
                            ->label(__('Serialized unit'))
                            ->options(fn (Get $get): array => self::serializedOptions($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => self::tracksSerials($get('product_variant_id')))
                            ->required(fn (Get $get): bool => self::tracksSerials($get('product_variant_id'))),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                    ])
                    ->authorize(fn (): bool => self::currentActor()->can('consume', $this->serviceRecord()))
                    ->visible(fn (): bool => $this->serviceRecord()->maintenanceRecord?->allowsRepairWork() ?? false)
                    ->action(function (array $data): void {
                        $productVariantId = $data['product_variant_id'] ?? null;
                        $warehouseId = $data['warehouse_id'] ?? null;
                        $quantity = $data['quantity'] ?? null;
                        $inventoryLotId = $data['inventory_lot_id'] ?? null;
                        $serializedInventoryUnitId = $data['serialized_inventory_unit_id'] ?? null;

                        // The Select/TextInput fields above are each ->required(), so
                        // Filament's own form validation guarantees numeric values here.
                        if (! is_numeric($productVariantId) || ! is_numeric($warehouseId) || ! is_numeric($quantity)) {
                            return;
                        }

                        app(ServiceRecordPartService::class)->consume(
                            $this->serviceRecord(),
                            (int) $productVariantId,
                            (int) $warehouseId,
                            (float) $quantity,
                            self::currentActor(),
                            is_numeric($inventoryLotId) ? (int) $inventoryLotId : null,
                            is_numeric($serializedInventoryUnitId) ? (int) $serializedInventoryUnitId : null,
                        );
                    }),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label(__('Reverse'))
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => self::currentActor()->can('reverse', MaintenanceTask::class))
                    ->visible(static fn (ServiceRecordPart $record): bool => $record->reversed_at === null)
                    ->action(static fn (ServiceRecordPart $record) => self::applyReversal($record)),
            ])
            ->toolbarActions([]);
    }

    private static function applyReversal(ServiceRecordPart $record): void
    {
        try {
            app(ServiceRecordPartService::class)->reverse($record, self::currentActor());
            // The row action's own ->visible() guard (reversed_at === null) means this
            // can never actually be reached through the action.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to reverse this consumption'))->body($domainException->getMessage())->send();
        }

    }

    private static function tracksBatches(mixed $variantId): bool
    {
        return is_numeric($variantId)
            && ProductVariant::query()->with('product')->find((int) $variantId)?->productType()?->tracksBatches() === true;
    }

    private static function tracksSerials(mixed $variantId): bool
    {
        return is_numeric($variantId)
            && ProductVariant::query()->with('product')->find((int) $variantId)?->productType()?->tracksSerials() === true;
    }

    /** @return array<int, string> */
    private static function lotOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $warehouseId = $get('warehouse_id');

        if (! is_numeric($variantId) || ! is_numeric($warehouseId)) {
            return [];
        }

        $variantId = (int) $variantId;
        $warehouseId = (int) $warehouseId;

        return app(InventoryLotService::class)
            ->availableLots($variantId, $warehouseId)
            ->mapWithKeys(function (InventoryLot $lot) use ($warehouseId): array {
                /** @var int $lotKey */
                $lotKey = $lot->getKey();

                return [
                    $lotKey => sprintf(
                        '%s — %.3f available',
                        $lot->lot_number ?? '#'.$lotKey,
                        $lot->availableQuantity($warehouseId),
                    ),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function serializedOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $warehouseId = $get('warehouse_id');

        if (! is_numeric($variantId) || ! is_numeric($warehouseId)) {
            return [];
        }

        $variantId = (int) $variantId;
        $warehouseId = (int) $warehouseId;
        $inventoryLotId = $get('inventory_lot_id');
        $inventoryLotId = is_numeric($inventoryLotId) ? (int) $inventoryLotId : null;

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerializedInventoryUnitStatus::Available->value)
            ->where('stock_condition', StockCondition::Saleable->value)
            ->when(
                $inventoryLotId !== null,
                fn (Builder $query): Builder => $query->where('inventory_lot_id', $inventoryLotId),
            )
            ->orderBy('serial_number')
            ->pluck('serial_number', 'id')
            ->mapWithKeys(static function (mixed $serialNumber, mixed $id): array {
                if (! is_numeric($id) || ! is_scalar($serialNumber)) {
                    return [];
                }

                return [(int) $id => (string) $serialNumber];
            })
            ->all();
    }

    private function serviceRecord(): MaintenanceTask
    {
        /** @var MaintenanceTask $record */
        $record = $this->getOwnerRecord();

        return $record;
    }

    private static function currentActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
