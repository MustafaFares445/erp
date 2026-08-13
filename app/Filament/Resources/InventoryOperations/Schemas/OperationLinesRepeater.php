<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\OperationType;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Services\Inventory\InventoryLotService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

final class OperationLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->columns(3)
            ->schema([
                Select::make('product_id')
                    ->label(__('admin.inventory.operation.fields.product'))
                    ->options(fn (): array => Product::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->placeholder(__('admin.inventory.operation.placeholders.product'))
                    ->dehydrated(false)
                    ->required()
                    ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                        if ($state !== null || ! is_numeric($get('product_variant_id'))) {
                            return;
                        }

                        $component->state(ProductVariant::query()
                            ->whereKey((int) $get('product_variant_id'))
                            ->value('product_id'));
                    })
                    ->afterStateUpdated(function (Set $set): void {
                        $set('product_variant_id', null);
                    }),
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.operation.fields.variant'))
                    ->options(fn (Get $get): array => self::variantOptions($get('product_id')))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => ! is_numeric($get('product_id')))
                    ->placeholder(__('admin.inventory.operation.placeholders.variant'))
                    // Live because the lot, expiry and serial fields below all depend on which
                    // variant — and therefore which product type — this line carries.
                    ->live()
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.operation.fields.demand'))
                    ->numeric()
                    ->minValue(0.001)
                    ->placeholder(__('admin.inventory.operation.placeholders.quantity'))
                    ->required(),
                Select::make('unit_id')
                    ->label(__('admin.inventory.operation.fields.unit'))
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('admin.inventory.operation.placeholders.unit'))
                    ->required(),
                // Expiry material, inbound: the line creates the lot, so it supplies the expiry
                // date. Without this field a receipt confirmed here produced stock with no
                // expiry date at all.
                DatePicker::make('expires_at')
                    ->label(__('admin.inventory.lot.fields.expires_at'))
                    ->placeholder(__('admin.inventory.lot.placeholders.expires_at'))
                    ->minDate(today())
                    ->visible(fn (Get $get): bool => self::isReceipt($get) && self::tracksExpiryOf($get))
                    ->required(fn (Get $get): bool => self::isReceipt($get) && self::tracksExpiryOf($get)),
                // Batch-tracked, inbound: the line creates the lot, so it supplies the lot's
                // identity — an expiry material and a bulk material like a sack of dental stone
                // powder both need this, even though only the former also needs an expiry date.
                TextInput::make('lot_number')
                    ->label(__('admin.inventory.lot.fields.lot_number'))
                    ->placeholder(__('admin.inventory.lot.placeholders.lot_number'))
                    ->maxLength(100)
                    ->visible(fn (Get $get): bool => self::isReceipt($get) && self::tracksBatchesOf($get)),
                // Batch-tracked, outbound: the line draws from a lot that already exists, so it
                // names one. Options are ordered first-expired-first-out (or oldest-first when
                // the batch carries no expiry).
                Select::make('inventory_lot_id')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->placeholder(__('admin.inventory.lot.placeholders.lot'))
                    ->options(fn (Get $get): array => self::lotOptions($get))
                    ->default(fn (Get $get): ?int => array_key_first(self::lotOptions($get)))
                    ->searchable()
                    ->visible(fn (Get $get): bool => ! self::isReceipt($get) && self::tracksBatchesOf($get))
                    ->required(fn (Get $get): bool => ! self::isReceipt($get) && self::tracksBatchesOf($get)),
                // Machine: one line is one device, identified by its serial.
                Select::make('serialized_inventory_unit_id')
                    ->label(__('admin.inventory.stock.serialized_unit'))
                    ->placeholder(__('admin.inventory.operation.placeholders.serialized_unit'))
                    ->options(fn (Get $get): array => self::serializedUnitOptions($get))
                    ->searchable()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === ProductType::Machine)
                    ->required(fn (Get $get): bool => self::typeOf($get) === ProductType::Machine),
                Select::make('package_id')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->relationship('package', 'name', function (Builder $query, Get $get): Builder {
                        $warehouseField = $get('../../operation_type') === OperationType::Receipt->value
                            ? '../../destination_warehouse_id'
                            : '../../source_warehouse_id';
                        $warehouseId = self::toInteger($get($warehouseField));

                        return $query
                            ->where('is_active', true)
                            ->when($warehouseId !== null, fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId));
                    })
                    ->searchable()
                    ->preload()
                    ->placeholder(__('admin.inventory.operation.placeholders.package')),
                Checkbox::make('is_picked')
                    ->label(__('admin.inventory.operation.fields.picked')),
            ])
            ->defaultItems(1)
            ->columnSpanFull();
    }

    /**
     * The product type governing this line, read from the product the row already names.
     *
     * Prefers `product_id`, which the row's own product select holds, and falls back to the
     * variant so a line hydrated from an existing record resolves before that select is filled.
     */
    private static function typeOf(Get $get): ?ProductType
    {
        $productId = self::toInteger($get('product_id'));

        if ($productId === null) {
            $variantId = self::toInteger($get('product_variant_id'));

            $productId = $variantId === null
                ? null
                : self::toInteger(ProductVariant::query()->whereKey($variantId)->value('product_id'));
        }

        if ($productId === null) {
            return null;
        }

        $type = Product::query()->withTrashed()->whereKey($productId)->value('product_type');

        return $type instanceof ProductType ? $type : null;
    }

    private static function tracksExpiryOf(Get $get): bool
    {
        return self::typeOf($get)?->tracksExpiry() === true;
    }

    private static function tracksBatchesOf(Get $get): bool
    {
        return self::typeOf($get)?->tracksBatches() === true;
    }

    private static function isReceipt(Get $get): bool
    {
        $operationType = $get('../../operation_type');

        return $operationType === OperationType::Receipt || $operationType === OperationType::Receipt->value;
    }

    /**
     * Usable batches at the source warehouse, earliest expiry first, so the operator's default
     * choice is the one that should leave first.
     *
     * Expired batches are deliberately absent: releasing one requires the override permission and
     * is a decision made at confirmation, not something to offer casually in a dropdown.
     *
     * @return array<int, string>
     */
    private static function lotOptions(Get $get): array
    {
        $variantId = self::toInteger($get('product_variant_id'));
        $warehouseId = self::toInteger($get('../../source_warehouse_id'));

        if ($variantId === null || $warehouseId === null) {
            return [];
        }

        $options = [];

        foreach (app(InventoryLotService::class)->availableLots($variantId, $warehouseId) as $lot) {
            $options[$lot->id] = $lot->expires_at === null
                ? __('admin.inventory.lot.option_no_expiry', [
                    'lot' => $lot->lot_number ?? '#'.$lot->id,
                    'available' => $lot->availableQuantity(),
                ])
                : __('admin.inventory.lot.option', [
                    'lot' => $lot->lot_number ?? '#'.$lot->id,
                    'date' => $lot->expires_at->toDateString(),
                    'available' => $lot->availableQuantity(),
                ]);
        }

        return $options;
    }

    /**
     * The devices this line may name: unregistered units for a receipt, and units actually
     * standing in the source warehouse for anything outbound.
     *
     * @return array<int, string>
     */
    private static function serializedUnitOptions(Get $get): array
    {
        $variantId = self::toInteger($get('product_variant_id'));

        if ($variantId === null) {
            return [];
        }

        $query = SerializedInventoryUnit::query()->where('product_variant_id', $variantId);

        if (self::isReceipt($get)) {
            $query->where('status', SerializedInventoryUnitStatus::Pending->value);
        } else {
            $warehouseId = self::toInteger($get('../../source_warehouse_id'));

            if ($warehouseId === null) {
                return [];
            }

            $query->where('warehouse_id', $warehouseId)
                ->where('status', SerializedInventoryUnitStatus::Available->value);
        }

        return $query
            ->orderBy('serial_number')
            ->get(['id', 'serial_number', 'iot_number'])
            ->mapWithKeys(static fn (SerializedInventoryUnit $unit): array => [
                $unit->id => $unit->iot_number === null
                    ? $unit->serial_number
                    : $unit->serial_number.' / '.$unit->iot_number,
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    private static function variantOptions(mixed $productId): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        return ProductVariant::query()
            ->where('product_id', (int) $productId)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku'])
            ->mapWithKeys(static function (ProductVariant $variant): array {
                $variantId = $variant->getKey();

                if (is_int($variantId)) {
                    return [$variantId => $variant->sku];
                }

                // @codeCoverageIgnoreStart
                // Unreachable in practice: ProductVariant's primary key is an auto-incrementing
                // integer column, so getKey() is always an int here. The guard exists only to
                // satisfy static analysis, which types getKey() as int|string.
                if (! is_string($variantId) || ! ctype_digit($variantId)) {
                    throw new \LogicException('An inventory operation variant must have a numeric ID.');
                }

                return [(int) $variantId => $variant->sku];
                // @codeCoverageIgnoreEnd
            })
            ->all();
    }

    private static function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
