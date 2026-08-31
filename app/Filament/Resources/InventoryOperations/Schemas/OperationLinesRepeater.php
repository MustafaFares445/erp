<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\OperationType;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Unit;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryOperationService;
use DomainException;
use Filament\Actions\Action;
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
                    ->options(fn (Get $get): array => self::productOptions($get('../../source_warehouse_id')))
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
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        $variantId = self::isOutbound($get)
                            ? self::singleVariantId($state, $get('../../source_warehouse_id'))
                            : null;
                        $set('product_variant_id', $variantId);
                        $set('unit_id', self::singleVariantUnitId($variantId));
                    }),
                // Outbound lines (delivery, internal transfer) only offer variants that still
                // carry stock at the source warehouse, and hide this select entirely once that
                // narrows the product down to a single variant — mirroring the delivery wizard's
                // warehouse-scoped assignment picker. Receipts have no source warehouse to check
                // against, so they keep the unfiltered, always-visible behavior.
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.operation.fields.variant'))
                    ->options(fn (Get $get): array => self::variantOptions($get('product_id'), $get('../../source_warehouse_id')))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => ! is_numeric($get('product_id')))
                    ->visible(fn (Get $get): bool => ! self::isOutbound($get)
                        || self::hasMultipleVariants($get('product_id'), $get('../../source_warehouse_id')))
                    ->required(fn (Get $get): bool => ! self::isOutbound($get)
                        || self::hasMultipleVariants($get('product_id'), $get('../../source_warehouse_id')))
                    ->dehydrated(true)
                    ->dehydratedWhenHidden()
                    ->placeholder(__('admin.inventory.operation.placeholders.variant'))
                    // Live because the lot, expiry and serial fields below all depend on which
                    // variant — and therefore which product type — this line carries.
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('unit_id', self::singleVariantUnitId($state));
                    }),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.operation.fields.demand'))
                    ->numeric()
                    ->minValue(0.001)
                    ->maxValue(fn (Get $get): ?float => self::isOutbound($get)
                        ? self::availableQuantity($get('product_variant_id'), $get('../../source_warehouse_id'))
                        : null)
                    ->validationMessages(['max' => __('admin.inventory.operation.errors.quantity_exceeds_available')])
                    ->placeholder(fn (Get $get): string => self::quantityPlaceholder($get))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (! self::isOutbound($get)) {
                            return;
                        }

                        $available = self::availableQuantity($get('product_variant_id'), $get('../../source_warehouse_id'));

                        if ($available !== null && is_numeric($state) && (float) $state > $available) {
                            $set('quantity', $available);
                        }
                    }),
                Select::make('unit_id')
                    ->label(__('admin.inventory.operation.fields.unit'))
                    ->options(fn (Get $get): array => self::unitOptions($get('product_variant_id')))
                    ->searchable()
                    ->preload()
                    ->placeholder(__('admin.inventory.operation.placeholders.unit'))
                    ->default(fn (Get $get): ?int => self::singleVariantUnitId($get('product_variant_id')))
                    ->visible(fn (Get $get): bool => self::hasMultipleUnits($get('product_variant_id')))
                    ->required()
                    ->dehydrated(true)
                    ->dehydratedWhenHidden(),
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
                // Machine: one line is one device, identified by its serial. A receipt is where
                // a device's serial first enters the system, so its line gets a "+" to type a
                // brand-new serial/IoT number inline rather than only picking one already
                // pre-registered elsewhere; outbound lines draw from devices already standing in
                // the source warehouse, so they only ever select.
                Select::make('serialized_inventory_unit_id')
                    ->label(__('admin.inventory.operation.fields.serialized_unit'))
                    ->placeholder(__('admin.inventory.operation.placeholders.serialized_unit'))
                    ->options(fn (Get $get): array => self::serializedUnitOptions($get))
                    ->searchable()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === ProductType::Machine)
                    ->required(fn (Get $get): bool => self::typeOf($get) === ProductType::Machine)
                    ->createOptionForm([
                        TextInput::make('serial_number')
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'serialized_inventory_units', ignoreRecord: true),
                        TextInput::make('iot_number')
                            ->maxLength(255)
                            ->unique(table: 'serialized_inventory_units', ignoreRecord: true),
                    ])
                    ->createOptionUsing(function (array $data, Get $get): int {
                        $variantId = self::toInteger($get('product_variant_id'));

                        if ($variantId === null) {
                            throw new DomainException(__('admin.inventory.operation.errors.serial_variant_required'));
                        }

                        $unit = SerializedInventoryUnit::query()->create([
                            'product_variant_id' => $variantId,
                            'serial_number' => $data['serial_number'],
                            'iot_number' => $data['iot_number'] ?? null,
                            'status' => SerializedInventoryUnitStatus::Pending,
                        ]);

                        $unitId = $unit->getKey();

                        if (is_int($unitId)) {
                            return $unitId;
                        }

                        // @codeCoverageIgnoreStart
                        // Unreachable in practice: SerializedInventoryUnit's primary key is an
                        // auto-incrementing integer column, so getKey() is always an int here.
                        // The guard exists only to satisfy static analysis, which types
                        // getKey() as int|string.
                        if (! is_string($unitId) || ! ctype_digit($unitId)) {
                            throw new \LogicException('A newly registered serialized unit must have a numeric ID.');
                        }

                        return (int) $unitId;
                        // @codeCoverageIgnoreEnd
                    })
                    ->createOptionAction(fn (Action $action, Get $get): Action => $action->visible(self::isReceipt($get))),
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
     * Delivery and internal transfer lines draw from an existing source warehouse balance, so
     * they get the warehouse-scoped product/variant/quantity filtering below. Receipts create
     * stock rather than drawing from it, so they're deliberately excluded.
     */
    private static function isOutbound(Get $get): bool
    {
        $operationType = $get('../../operation_type');

        return $operationType === OperationType::Delivery || $operationType === OperationType::Delivery->value
            || $operationType === OperationType::InternalTransfer || $operationType === OperationType::InternalTransfer->value;
    }

    /**
     * Active products, narrowed to those with available stock at the given warehouse once one is
     * known. Mirrors the delivery wizard's warehouse-scoped product picker.
     *
     * @return array<int, string>
     */
    private static function productOptions(mixed $warehouseId): array
    {
        $query = Product::query()->where('is_active', true);

        $warehouseId = self::toInteger($warehouseId);

        if ($warehouseId !== null) {
            $query->whereHas('variants', fn (Builder $variants): Builder => $variants
                ->where('is_active', true)
                ->whereHas('stocks', fn (Builder $stocks): Builder => $stocks
                    ->where('warehouse_id', $warehouseId)
                    ->where('available_quantity', '>', 0)));
        }

        return $query->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static function (Product $product): array {
                $productId = $product->getKey();

                if (is_int($productId)) {
                    return [$productId => $product->name];
                }

                // @codeCoverageIgnoreStart
                // Unreachable in practice: Product's primary key is an auto-incrementing integer
                // column, so getKey() is always an int here. The guard exists only to satisfy
                // static analysis, which types getKey() as int|string.
                if (! is_string($productId) || ! ctype_digit($productId)) {
                    throw new \LogicException('An inventory operation product must have a numeric ID.');
                }

                return [(int) $productId => $product->name];
                // @codeCoverageIgnoreEnd
            })
            ->all();
    }

    private static function hasMultipleVariants(mixed $productId, mixed $warehouseId): bool
    {
        return count(self::variantOptions($productId, $warehouseId)) > 1;
    }

    private static function hasMultipleUnits(mixed $variantId): bool
    {
        return count(self::unitOptions($variantId)) > 1;
    }

    private static function singleVariantUnitId(mixed $variantId): ?int
    {
        $unitIds = array_keys(self::unitOptions($variantId));

        return count($unitIds) === 1 ? (int) $unitIds[0] : null;
    }

    private static function singleVariantId(mixed $productId, mixed $warehouseId): ?int
    {
        $variantIds = array_keys(self::variantOptions($productId, $warehouseId));

        return count($variantIds) === 1 ? (int) $variantIds[0] : null;
    }

    private static function availableQuantity(mixed $variantId, mixed $warehouseId): ?float
    {
        $variantId = self::toInteger($variantId);
        $warehouseId = self::toInteger($warehouseId);

        if ($variantId === null || $warehouseId === null) {
            return null;
        }

        return app(InventoryOperationService::class)->availableQuantity($variantId, $warehouseId);
    }

    private static function quantityPlaceholder(Get $get): string
    {
        if (! self::isOutbound($get)) {
            return __('admin.inventory.operation.placeholders.quantity');
        }

        $variantId = self::toInteger($get('product_variant_id'));
        $warehouseId = self::toInteger($get('../../source_warehouse_id'));

        if ($variantId === null || $warehouseId === null) {
            return __('admin.inventory.operation.placeholders.quantity_select_product');
        }

        $availableQuantity = self::availableQuantity($variantId, $warehouseId);

        if ($availableQuantity === null) {
            return __('admin.inventory.operation.placeholders.quantity_no_stock');
        }

        $formattedQuantity = mb_rtrim(mb_rtrim(number_format($availableQuantity, 3, '.', ''), '0'), '.');

        return __('admin.inventory.operation.placeholders.quantity_available_amount', ['quantity' => $formattedQuantity]);
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
    private static function variantOptions(mixed $productId, mixed $warehouseId = null): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        $query = ProductVariant::query()
            ->where('product_id', (int) $productId)
            ->where('is_active', true);

        $warehouseId = self::toInteger($warehouseId);

        if ($warehouseId !== null) {
            $query->whereHas('stocks', fn (Builder $stocks): Builder => $stocks
                ->where('warehouse_id', $warehouseId)
                ->where('available_quantity', '>', 0));
        }

        return $query
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

    /** @return array<int, string> */
    private static function unitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        $variant = ProductVariant::query()->find((int) $variantId);

        if (! $variant instanceof ProductVariant) {
            return [];
        }

        $options = [];

        foreach ($variant->variantUnits()
            ->with('unit')
            ->where('is_active', true)
            ->get() as $variantUnit) {
            $unit = $variantUnit->unit;

            if (! $unit instanceof Unit || ! $unit->is_active) {
                continue;
            }

            $unitId = self::toInteger($unit->getKey());

            if ($unitId === null) {
                throw new \LogicException('A variant operation unit must have a numeric ID.');
            }

            $options[$unitId] = $unit->name;
        }

        asort($options);

        return $options;
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
