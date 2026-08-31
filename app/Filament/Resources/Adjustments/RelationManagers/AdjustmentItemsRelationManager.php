<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\RelationManagers;

use App\Models\InventoryAdjustment;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryLotService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Item lines for a draft {@see InventoryAdjustment} (FR-003…FR-007).
 *
 * `old_quantity`/`difference` are always read from the **live**
 * `(variant, warehouse)` balance for display — never the stored row value —
 * and are never dehydrated: {@see InventoryAdjustmentService::confirm()}
 * finalizes and persists them at confirm time (research R7). Add/edit/
 * remove is only reachable while the parent adjustment is a draft (FR-006);
 * everything here is inert with respect to stock (FR-007).
 */
final class AdjustmentItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.stock.variant'))
                    ->relationship('productVariant', 'sku')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Select the variant whose recorded stock needs correction.')
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $set('inventory_lot_id', null);
                        $set('serialized_inventory_unit_id', null);
                        $oldQuantity = $this->liveCount($get, $state);
                        $set('old_quantity', $oldQuantity);
                        $set('difference', $this->toFloat($get('new_quantity')) - $oldQuantity);
                    }),
                Select::make('inventory_lot_id')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->options(fn (Get $get): array => $this->lotOptions($get))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->visible(fn (Get $get): bool => $this->tracksBatches($get('product_variant_id')))
                    ->required(fn (Get $get): bool => $this->tracksBatches($get('product_variant_id')))
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $oldQuantity = $this->liveCount($get, $get('product_variant_id'));
                        $set('old_quantity', $oldQuantity);
                        $set('difference', $this->toFloat($get('new_quantity')) - $oldQuantity);
                        $set('serialized_inventory_unit_id', null);
                    }),
                Select::make('serialized_inventory_unit_id')
                    ->label('Serialized unit')
                    ->options(fn (Get $get): array => $this->serializedOptions($get))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->visible(fn (Get $get): bool => $this->tracksSerials($get('product_variant_id')))
                    ->required(fn (Get $get): bool => $this->tracksSerials($get('product_variant_id')))
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $oldQuantity = $this->liveCount($get, $get('product_variant_id'));
                        $set('old_quantity', $oldQuantity);
                        $set('difference', $this->toFloat($get('new_quantity')) - $oldQuantity);
                    }),
                Select::make('package_id')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->relationship('package', 'name', fn (Builder $query): Builder => $query
                        ->where('warehouse_id', $this->adjustment()->warehouse_id)
                        ->where('is_active', true))
                    ->searchable()
                    ->preload(),
                TextInput::make('old_quantity')
                    ->label(__('admin.inventory.adjustment.old_quantity'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (Get $get): float => $this->liveCount($get, $get('product_variant_id'))),
                TextInput::make('new_quantity')
                    ->label(__('admin.inventory.adjustment.new_quantity'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true)
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Enter the physical quantity counted. The difference is calculated against the live on-hand quantity.')
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $set('difference', $this->toFloat($state) - $this->liveCount($get, $get('product_variant_id')));
                    }),
                TextInput::make('difference')
                    ->label(__('admin.inventory.adjustment.difference'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'This is calculated automatically as the new quantity minus the current on-hand quantity.')
                    ->formatStateUsing(fn (Get $get): float => $this->toFloat($get('new_quantity')) - $this->liveCount($get, $get('product_variant_id'))),
            ])
            ->disabled(fn (): bool => ! $this->adjustment()->isDraft());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant')),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.inventory.stock.variant_name')),
                TextColumn::make('lot.lot_number')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->placeholder('—'),
                TextColumn::make('serializedUnit.serial_number')
                    ->label('Serial')
                    ->placeholder('—'),
                TextColumn::make('package.name')
                    ->label(__('admin.inventory.operation.fields.package')),
                TextColumn::make('old_quantity')
                    ->label(__('admin.inventory.adjustment.old_quantity'))
                    ->state(fn (InventoryAdjustmentItem $record): float => $this->displayOldQuantity($record)),
                TextColumn::make('new_quantity')
                    ->label(__('admin.inventory.adjustment.new_quantity')),
                TextColumn::make('difference')
                    ->label(__('admin.inventory.adjustment.difference'))
                    ->state(fn (InventoryAdjustmentItem $record): float => (float) $record->new_quantity - $this->displayOldQuantity($record)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->adjustment()->isDraft()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->adjustment()->isDraft()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->adjustment()->isDraft()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])->visible(fn (): bool => $this->adjustment()->isDraft()),
            ]);
    }

    /**
     * While the parent is still a draft, the live balance is the accurate
     * "current quantity" (it may have moved since the line was added). Once
     * confirmed, the item's persisted `old_quantity` is the historical
     * truth {@see InventoryAdjustmentService::confirm()}
     * finalized — showing the (possibly different) live balance instead
     * would misrepresent what the confirmation actually applied.
     */
    private function displayOldQuantity(InventoryAdjustmentItem $item): float
    {
        if ($this->adjustment()->isDraft()) {
            return $this->liveItemCount($item);
        }

        return (float) $item->old_quantity;
    }

    private function tracksBatches(mixed $variantId): bool
    {
        return is_numeric($variantId)
            && ProductVariant::query()->with('product')->find((int) $variantId)?->productType()?->tracksBatches() === true;
    }

    private function tracksSerials(mixed $variantId): bool
    {
        return is_numeric($variantId)
            && ProductVariant::query()->with('product')->find((int) $variantId)?->productType()?->tracksSerials() === true;
    }

    /** @return array<int, string> */
    private function lotOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');

        if (! is_numeric($variantId)) {
            return [];
        }

        $warehouseId = (int) $this->adjustment()->warehouse_id;

        return app(InventoryLotService::class)
            ->availableLots((int) $variantId, $warehouseId)
            ->mapWithKeys(fn (InventoryLot $lot): array => [
                (int) $lot->getKey() => sprintf(
                    '%s — %.3f available',
                    $lot->lot_number ?? '#'.$lot->getKey(),
                    $lot->availableQuantity($warehouseId),
                ),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function serializedOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');

        if (! is_numeric($variantId)) {
            return [];
        }

        $warehouseId = (int) $this->adjustment()->warehouse_id;
        $lotId = $get('inventory_lot_id');

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerializedInventoryUnitStatus::Available->value)
            ->where('stock_condition', StockCondition::Saleable->value)
            ->when(
                is_numeric($lotId),
                fn (Builder $query): Builder => $query->where('inventory_lot_id', (int) $lotId),
            )
            ->orderBy('serial_number')
            ->pluck('serial_number', 'id')
            ->all();
    }

    private function liveCount(Get $get, mixed $productVariantId): float
    {
        if (! is_numeric($productVariantId)) {
            return 0.0;
        }

        $warehouseId = (int) $this->adjustment()->warehouse_id;
        $serializedUnitId = $get('serialized_inventory_unit_id');

        if (is_numeric($serializedUnitId)) {
            return SerializedInventoryUnit::query()
                ->whereKey((int) $serializedUnitId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', SerializedInventoryUnitStatus::Available->value)
                ->where('stock_condition', StockCondition::Saleable->value)
                ->exists() ? 1.0 : 0.0;
        }

        $lotId = $get('inventory_lot_id');

        if (is_numeric($lotId)) {
            $lot = InventoryLot::query()->canonical()->find((int) $lotId);

            return $lot instanceof InventoryLot
                ? $lot->conditionOnHandQuantity(StockCondition::Saleable, $warehouseId)
                : 0.0;
        }

        $warehouse = $this->adjustment()->warehouse;

        if (! $warehouse instanceof Warehouse) {
            return 0.0;
        }

        return $warehouse->currentOnHand((int) $productVariantId);
    }

    private function liveItemCount(InventoryAdjustmentItem $item): float
    {
        $warehouseId = (int) $this->adjustment()->warehouse_id;

        if ($item->serialized_inventory_unit_id !== null) {
            return SerializedInventoryUnit::query()
                ->whereKey($item->serialized_inventory_unit_id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', SerializedInventoryUnitStatus::Available->value)
                ->where('stock_condition', StockCondition::Saleable->value)
                ->exists() ? 1.0 : 0.0;
        }

        if ($item->inventory_lot_id !== null) {
            $lot = InventoryLot::query()->canonical()->find($item->inventory_lot_id);

            return $lot instanceof InventoryLot
                ? $lot->conditionOnHandQuantity(StockCondition::Saleable, $warehouseId)
                : 0.0;
        }

        $warehouse = $this->adjustment()->warehouse;

        return $warehouse instanceof Warehouse
            ? $warehouse->currentOnHand((int) $item->product_variant_id)
            : 0.0;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * The owner record is always an {@see InventoryAdjustment} — this
     * resource has no other parent — but `getOwnerRecord()` is typed as
     * the generic base `Model`. Narrowing here (once) lets every caller use
     * `InventoryAdjustment`'s own methods without repeating the check.
     */
    private function adjustment(): InventoryAdjustment
    {
        $record = $this->getOwnerRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice: this relation manager is only ever mounted
        // on AdjustmentResource's pages, so the owner record is always an
        // InventoryAdjustment. The guard exists only to satisfy static
        // analysis (getOwnerRecord() is typed as the generic base Model).
        if (! $record instanceof InventoryAdjustment) {
            throw new LogicException('Expected the owner record of AdjustmentItemsRelationManager to be an InventoryAdjustment.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }
}
