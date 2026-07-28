<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\RelationManagers;

use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\InventoryAdjustmentService;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
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
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $oldQuantity = $this->liveOnHand($state);
                        $set('old_quantity', $oldQuantity);
                        $set('difference', $this->toFloat($get('new_quantity')) - $oldQuantity);
                    }),
                Select::make('serialized_inventory_unit_id')
                    ->label('Serialized unit')
                    ->relationship('serializedUnit', 'serial_number')
                    ->searchable()
                    ->preload(),
                Select::make('warehouse_location_id')
                    ->label(__('admin.inventory.adjustment.location'))
                    ->options(fn (): array => WarehouseLocation::query()
                        ->where('warehouse_id', $this->adjustment()->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->rules([
                        fn () => Rule::exists('warehouse_locations', 'id')
                            ->where('warehouse_id', $this->adjustment()->warehouse_id)
                            ->where('is_active', true),
                    ]),
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
                    ->formatStateUsing(fn (Get $get): float => $this->liveOnHand($get('product_variant_id'))),
                TextInput::make('new_quantity')
                    ->label(__('admin.inventory.adjustment.new_quantity'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        $set('difference', $this->toFloat($state) - $this->liveOnHand($get('product_variant_id')));
                    }),
                TextInput::make('difference')
                    ->label(__('admin.inventory.adjustment.difference'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (Get $get): float => $this->toFloat($get('new_quantity')) - $this->liveOnHand($get('product_variant_id'))),
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
                TextColumn::make('location.name')
                    ->label(__('admin.inventory.adjustment.location'))
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
            return $this->liveOnHand($item->product_variant_id);
        }

        return (float) $item->old_quantity;
    }

    private function liveOnHand(mixed $productVariantId): float
    {
        if (! is_numeric($productVariantId)) {
            return 0.0;
        }

        $warehouse = $this->adjustment()->warehouse;

        // @codeCoverageIgnoreStart
        // Unreachable in practice: warehouse_id is a NOT NULL FK with
        // restrictOnDelete, so the relation always resolves. The guard exists
        // only to satisfy static analysis (BelongsTo is typed nullable).
        if (! $warehouse instanceof Warehouse) {
            return 0.0;
        }

        // @codeCoverageIgnoreEnd

        return $warehouse->currentOnHand((int) $productVariantId);
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
