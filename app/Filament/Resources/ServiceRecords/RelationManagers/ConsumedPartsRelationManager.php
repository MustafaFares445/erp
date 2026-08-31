<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\RelationManagers;

use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryLot;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Support\ServiceRecordPartService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Table;
use LogicException;

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
                TextColumn::make('productVariant.name')->label('Product variant'),
                TextColumn::make('warehouse.name')->label('Warehouse'),
                TextColumn::make('lot.lot_number')->label('Lot')->placeholder('—'),
                TextColumn::make('serializedUnit.serial_number')->label('Serial')->placeholder('—'),
                TextColumn::make('quantity')->numeric(6),
                TextColumn::make('createdBy.name')->label('Consumed by'),
                TextColumn::make('created_at')->label('Consumed at')->dateTime(),
                TextColumn::make('reversed_at')->label('Reversed at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('consumePart')
                    ->label('Consume Part')
                    ->schema([
                        Select::make('product_variant_id')
                            ->label('Product variant')
                            ->relationship('productVariant', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->relationship('warehouse', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('inventory_lot_id')
                            ->label('Lot')
                            ->options(fn (Get $get): array => self::lotOptions($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => self::tracksBatches($get('product_variant_id')))
                            ->required(fn (Get $get): bool => self::tracksBatches($get('product_variant_id'))),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Serialized unit')
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
                    ->action(function (array $data): void {
                        $productVariantId = $data['product_variant_id'] ?? null;
                        $warehouseId = $data['warehouse_id'] ?? null;
                        $quantity = $data['quantity'] ?? null;
                        $inventoryLotId = $data['inventory_lot_id'] ?? null;
                        $serializedInventoryUnitId = $data['serialized_inventory_unit_id'] ?? null;

                        // @codeCoverageIgnoreStart
                        // The Select/TextInput fields above are each ->required(), so
                        // Filament's own form validation guarantees numeric values here.
                        if (! is_numeric($productVariantId) || ! is_numeric($warehouseId) || ! is_numeric($quantity)) {
                            return;
                        }

                        // @codeCoverageIgnoreEnd

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
                    ->label('Reverse')
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
            // @codeCoverageIgnoreStart
            // The row action's own ->visible() guard (reversed_at === null) means this
            // can never actually be reached through the action.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to reverse this consumption')->body($domainException->getMessage())->send();
        }

        // @codeCoverageIgnoreEnd
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

        return InventoryLot::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('warehouse_id', (int) $warehouseId)
            ->where('on_hand_quantity', '>', 0)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (InventoryLot $lot): array => [
                (int) $lot->getKey() => $lot->lot_number ?? '#'.$lot->getKey(),
            ])
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

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('warehouse_id', (int) $warehouseId)
            ->where('status', SerializedInventoryUnitStatus::Available->value)
            ->orderBy('serial_number')
            ->pluck('serial_number', 'id')
            ->all();
    }

    private function serviceRecord(): MaintenanceTask
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof MaintenanceTask) {
            throw new LogicException('Expected the owner record of ConsumedPartsRelationManager to be a MaintenanceTask.');
        }

        return $record;
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        // The admin panel's own auth middleware guarantees an authenticated User here.
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        // @codeCoverageIgnoreEnd

        return $actor;
    }
}
