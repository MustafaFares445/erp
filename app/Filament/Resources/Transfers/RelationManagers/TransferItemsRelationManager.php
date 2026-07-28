<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\RelationManagers;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockTransferService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Item lines for a draft {@see StockTransfer} (FR-001…FR-005).
 *
 * `quantity` is the amount to move — validated greater than zero. Duplicate
 * lines for the same variant are permitted (research D4): the source's
 * available quantity is only checked (summed) at confirm time by
 * {@see StockTransferService::confirm()}; this
 * manager itself never touches stock (FR-006). Add/edit/remove is only
 * reachable while the parent transfer is a draft, and each change touches
 * the parent so it registers as an `inventory.transfer.edited` audit row
 * (FR-014a).
 */
final class TransferItemsRelationManager extends RelationManager
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
                    ->live(),
                Select::make('serialized_inventory_unit_id')
                    ->label('Serialized unit')
                    ->relationship('serializedUnit', 'serial_number')
                    ->searchable()
                    ->preload(),
                Select::make('warehouse_location_id')
                    ->label(__('admin.inventory.transfer.destination_location'))
                    ->options(fn (): array => WarehouseLocation::query()
                        ->where('warehouse_id', $this->transfer()->to_warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->rules([
                        fn () => Rule::exists('warehouse_locations', 'id')
                            ->where('warehouse_id', $this->transfer()->to_warehouse_id)
                            ->where('is_active', true),
                    ]),
                Select::make('package_id')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->relationship('package', 'name', fn (Builder $query): Builder => $query
                        ->where('warehouse_id', $this->transfer()->from_warehouse_id)
                        ->where('is_active', true))
                    ->searchable()
                    ->preload(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.transfer.quantity'))
                    ->numeric()
                    ->minValue(0)
                    ->rules(['gt:0'])
                    ->required(),
                TextInput::make('available_at_source')
                    ->label(__('admin.inventory.transfer.available'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (Get $get): float => $this->liveAvailable($get('product_variant_id'))),
            ])
            ->disabled(fn (): bool => ! $this->transfer()->isDraft());
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
                TextColumn::make('quantity')
                    ->label(__('admin.inventory.transfer.quantity')),
                TextColumn::make('location.name')
                    ->label(__('admin.inventory.transfer.destination_location'))
                    ->placeholder('—'),
                TextColumn::make('package.name')
                    ->label(__('admin.inventory.operation.fields.package')),
                TextColumn::make('available_at_source')
                    ->label(__('admin.inventory.transfer.available'))
                    ->state(fn (StockTransferItem $record): float => $this->liveAvailable($record->product_variant_id)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->transfer()->isDraft())
                    ->after(fn () => $this->transfer()->touch()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->transfer()->isDraft())
                    ->after(fn () => $this->transfer()->touch()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->transfer()->isDraft())
                    ->after(fn () => $this->transfer()->touch()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn () => $this->transfer()->touch()),
                ])->visible(fn (): bool => $this->transfer()->isDraft()),
            ]);
    }

    private function liveAvailable(mixed $productVariantId): float
    {
        if (! is_numeric($productVariantId)) {
            return 0.0;
        }

        return $this->fromWarehouse()->currentAvailable((int) $productVariantId);
    }

    /**
     * The owner record is always a {@see StockTransfer} — this resource has
     * no other parent — but `getOwnerRecord()` is typed as the generic base
     * `Model`. Narrowing here (once) lets every caller use `StockTransfer`'s
     * own methods without repeating the check.
     */
    private function transfer(): StockTransfer
    {
        $record = $this->getOwnerRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice: this relation manager is only ever mounted
        // on TransferResource's pages, so the owner record is always a
        // StockTransfer. The guard exists only to satisfy static analysis
        // (getOwnerRecord() is typed as the generic base Model).
        if (! $record instanceof StockTransfer) {
            throw new LogicException('Expected the owner record of TransferItemsRelationManager to be a StockTransfer.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }

    private function fromWarehouse(): Warehouse
    {
        $warehouse = $this->transfer()->fromWarehouse;

        // @codeCoverageIgnoreStart
        // Unreachable in practice: from_warehouse_id is a NOT NULL FK with
        // restrictOnDelete, so the relation always resolves. The guard
        // exists only to satisfy static analysis (BelongsTo is typed
        // nullable).
        if (! $warehouse instanceof Warehouse) {
            throw new LogicException('Expected the transfer to have a from-warehouse.');
        }

        // @codeCoverageIgnoreEnd

        return $warehouse;
    }
}
