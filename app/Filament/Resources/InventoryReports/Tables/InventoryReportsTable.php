<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Tables;

use App\Enums\InventoryReportType;
use App\Enums\ProductType;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierProductReference;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class InventoryReportsTable
{
    public static function configure(Table $table, InventoryReportType $type, bool $canViewPricing): Table
    {
        return $table
            ->columns(self::columns($type, $canViewPricing))
            ->filters(InventoryReportFilters::for($type))
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** @return array<int, TextColumn|IconColumn> */
    private static function columns(InventoryReportType $type, bool $canViewPricing): array
    {
        return match ($type) {
            InventoryReportType::Catalog => self::catalogColumns($canViewPricing),
            InventoryReportType::StockLevels => self::stockColumns($canViewPricing),
            InventoryReportType::Movements => self::movementColumns(),
            InventoryReportType::Devices => self::deviceColumns(),
            InventoryReportType::ExpiryLots => self::expiryColumns(),
            InventoryReportType::QuarantineAgeing => self::quarantineAgeingColumns(),
            InventoryReportType::SupplierComparison => self::supplierColumns(),
            InventoryReportType::PriceHistory => self::priceHistoryColumns(),
            InventoryReportType::PricingTiers => self::pricingTierColumns(),
            InventoryReportType::CustomerAssignments => self::customerAssignmentColumns(),
            InventoryReportType::FloorOverrides => self::floorOverrideColumns(),
            InventoryReportType::ImportRuns => self::importRunColumns(),
            InventoryReportType::ImportResults => self::importResultColumns(),
        };
    }

    /** @return array<int, TextColumn|IconColumn> */
    private static function catalogColumns(bool $canViewPricing): array
    {
        $columns = [
            TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('name')->label(self::label('variant'))->searchable(),
            TextColumn::make('product.name')->label(self::label('product')),
            TextColumn::make('product.product_type')
                ->label(__('admin.inventory.product_type.label'))
                ->badge()
                ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                ->color(static fn (ProductType $state): string => $state->color()),
            TextColumn::make('net_weight')
                ->label(__('admin.inventory.product_type.fields.net_weight'))
                ->numeric(decimalPlaces: 3)
                ->suffix(static fn (ProductVariant $record): string => $record->weightSuffix())
                ->placeholder('—'),
            TextColumn::make('product.brand.name')->label(self::label('brand')),
            TextColumn::make('product.category.name')->label(self::label('category')),
            TextColumn::make('unit.symbol')->label(self::label('unit')),
            TextColumn::make('barcode')->label(self::label('barcode')),
            TextColumn::make('supplier_references_count')->label(self::label('suppliers'))->numeric(),
            TextColumn::make('status')->label(self::label('status'))->badge(),
            IconColumn::make('is_active')->label(self::label('active'))->boolean(),
        ];

        if ($canViewPricing) {
            $columns[] = TextColumn::make('cost_price')->label(self::label('cost'))->money('USD');
            $columns[] = TextColumn::make('base_price')->label(self::label('base_price'))->money('USD');
            $columns[] = TextColumn::make('min_price')->label(self::label('min_price'))->money('USD');
        }

        return $columns;
    }

    /** @return array<int, TextColumn> */
    private static function stockColumns(bool $canViewPricing): array
    {
        $columns = [
            TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('productVariant.product.product_type')
                ->label(__('admin.inventory.product_type.label'))
                ->badge()
                ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                ->color(static fn (ProductType $state): string => $state->color()),
            TextColumn::make('warehouse.name')->label(self::label('warehouse'))->searchable(),
            TextColumn::make('on_hand_quantity')->label(self::label('on_hand'))->numeric(decimalPlaces: 3),
            TextColumn::make('reserved_quantity')->label(self::label('reserved'))->numeric(decimalPlaces: 3),
            TextColumn::make('damaged_quantity')->label(self::label('damaged'))->numeric(decimalPlaces: 3),
            TextColumn::make('available_quantity')->label(self::label('available'))->numeric(decimalPlaces: 3),
            TextColumn::make('total_weight')
                ->label(__('admin.inventory.product_type.fields.total_weight'))
                ->state(fn (InventoryStock $record): ?float => $record->productVariant?->weightFor((float) $record->on_hand_quantity))
                ->suffix(fn (InventoryStock $record): string => $record->productVariant?->weightSuffix() ?? '')
                ->numeric(decimalPlaces: 3)
                ->placeholder('—'),
        ];

        if ($canViewPricing) {
            $columns[] = TextColumn::make('usable_value')
                ->label(self::label('usable_value'))
                ->money('USD')
                ->state(fn (InventoryStock $record): float => (float) $record->available_quantity * (float) ($record->productVariant->cost_price ?? 0));
        }

        return $columns;
    }

    /** @return array<int, TextColumn> */
    private static function movementColumns(): array
    {
        return [
            TextColumn::make('created_at')->label(self::label('date'))->dateTime()->sortable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('warehouse.name')->label(self::label('warehouse')),
            TextColumn::make('movement_type')->label(self::label('type'))->badge(),
            TextColumn::make('transaction_quantity')
                ->label(self::label('transaction_quantity'))
                ->numeric(decimalPlaces: 6)
                ->placeholder('—'),
            TextColumn::make('transactionUnit.symbol')->label(self::label('unit'))->placeholder('—'),
            TextColumn::make('base_quantity_delta')
                ->label(self::label('base_quantity_delta'))
                ->numeric(decimalPlaces: 6)
                ->placeholder('—'),
            TextColumn::make('stock_condition_from')
                ->label(self::label('condition_from'))
                ->badge()
                ->placeholder('—'),
            TextColumn::make('stock_condition_to')
                ->label(self::label('condition_to'))
                ->badge()
                ->placeholder('—'),
            TextColumn::make('lot.lot_number')->label(self::label('lot'))->placeholder('—'),
            TextColumn::make('serializedUnit.serial_number')->label(self::label('serial'))->placeholder('—'),
            TextColumn::make('package.name')->label(self::label('package'))->placeholder('—'),
            TextColumn::make('source_type')->label(self::label('source')),
            TextColumn::make('source_line_type')
                ->label(self::label('source_line'))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('reversal_of_movement_id')
                ->label(self::label('reversal'))
                ->placeholder('—')
                ->toggleable(),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function deviceColumns(): array
    {
        return [
            TextColumn::make('serial_number')
                ->label(self::label('serial'))
                ->searchable()
                ->url(fn (SerializedInventoryUnit $record): ?string => AdminModuleRegistry::resolveResourceRecordLink(
                    SerializedInventoryUnitResource::class,
                    self::integerKey($record),
                )),
            TextColumn::make('iot_number')->label(self::label('iot'))->searchable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('status')->label(self::label('status'))->badge(),
            TextColumn::make('warehouse.name')->label(self::label('warehouse')),
            TextColumn::make('receipt_source')
                ->label(self::label('receipt'))
                ->state(function (SerializedInventoryUnit $record): ?string {
                    $movement = $record->receiptMovement;

                    if (! $movement instanceof InventoryMovement) {
                        return null;
                    }

                    return sprintf(
                        '%s #%d',
                        $movement->source_type ?? 'inventory_movement',
                        is_numeric($movement->source_id)
                            ? (int) $movement->source_id
                            : self::integerKey($movement),
                    );
                })
                ->placeholder('—'),
            TextColumn::make('movements_count')->label(self::label('movements'))->numeric(),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function expiryColumns(): array
    {
        return [
            TextColumn::make('expires_at')->label(self::label('expiry'))->date()->sortable(),
            TextColumn::make('lot_number')->label(self::label('lot')),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('warehouse.name')->label(self::label('warehouse')),
            TextColumn::make('on_hand_quantity')->label(self::label('on_hand'))->numeric(decimalPlaces: 3),
            TextColumn::make('reserved_quantity')->label(self::label('reserved'))->numeric(decimalPlaces: 3),
            TextColumn::make('available_quantity')
                ->label(self::label('available'))
                ->numeric(decimalPlaces: 3)
                ->state(fn (InventoryLot $record): float => $record->totalAvailableQuantity()),
            TextColumn::make('expiry_state')
                ->label(self::label('state'))
                ->badge()
                ->state(fn (InventoryLot $record): string => $record->expiryState()),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function quarantineAgeingColumns(): array
    {
        return [
            TextColumn::make('lot.productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('lot.productVariant.name')->label(self::label('variant')),
            TextColumn::make('warehouse.name')->label(self::label('warehouse'))->searchable(),
            TextColumn::make('lot.lot_number')->label(self::label('lot'))->placeholder('—'),
            TextColumn::make('on_hand_base_quantity')->label(self::label('quantity'))->numeric(decimalPlaces: 6),
            TextColumn::make('oldest_quarantine_at')
                ->label(self::label('entered_quarantine'))
                ->dateTime()
                ->state(fn (InventoryLotBalance $record): mixed => $record->getAttribute('oldest_quarantine_at') ?? $record->created_at),
            TextColumn::make('days_in_quarantine')
                ->label(self::label('days_in_quarantine'))
                ->state(function (InventoryLotBalance $record): int {
                    $value = $record->getAttribute('oldest_quarantine_at') ?? $record->created_at;

                    return $value === null ? 0 : (int) CarbonImmutable::parse((string) $value)->diffInDays(now());
                }),
            TextColumn::make('ageing_bucket')
                ->label(self::label('ageing_bucket'))
                ->badge()
                ->state(function (InventoryLotBalance $record): string {
                    $value = $record->getAttribute('oldest_quarantine_at') ?? $record->created_at;
                    $days = $value === null ? 0 : (int) CarbonImmutable::parse((string) $value)->diffInDays(now());

                    return match (true) {
                        $days <= 7 => '0-7',
                        $days <= 30 => '8-30',
                        $days <= 90 => '31-90',
                        default => '90+',
                    };
                }),
            TextColumn::make('inbound_document')
                ->label(self::label('inbound_document'))
                ->state(function (InventoryLotBalance $record): string {
                    $type = $record->getAttribute('inbound_source_type');
                    $id = $record->getAttribute('inbound_source_id');

                    return is_string($type) && is_numeric($id)
                        ? sprintf('%s #%d', $type, (int) $id)
                        : 'Pre-WP-1.1 / inbound document unknown';
                }),
        ];
    }

    /** @return array<int, TextColumn|IconColumn> */
    private static function supplierColumns(): array
    {
        return [
            TextColumn::make('supplier.name')->label(self::label('supplier'))->searchable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('supplier_item_number')->label(self::label('supplier_item')),
            TextColumn::make('manufacturer')->label(self::label('manufacturer')),
            TextColumn::make('country_code')->label(self::label('country')),
            TextColumn::make('purchase_cost')
                ->label(self::label('supplier_price'))
                ->formatStateUsing(fn (SupplierProductReference $record): string => number_format((float) $record->purchase_cost, 2).' '.$record->currency_code),
            IconColumn::make('is_active')->label(self::label('active'))->boolean(),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function priceHistoryColumns(): array
    {
        return [
            TextColumn::make('created_at')->label(self::label('date'))->dateTime()->sortable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('cost_price')->label(self::label('cost'))->money('USD'),
            TextColumn::make('base_price')->label(self::label('base_price'))->money('USD'),
            TextColumn::make('min_price')->label(self::label('min_price'))->money('USD'),
            TextColumn::make('markup_percent')->label(self::label('markup'))->suffix('%'),
            TextColumn::make('changedBy.name')->label(self::label('changed_by')),
        ];
    }

    /** @return array<int, TextColumn|IconColumn> */
    private static function pricingTierColumns(): array
    {
        return [
            TextColumn::make('name')->label(self::label('tier'))->searchable(),
            TextColumn::make('tier_type')->label('Type')->badge(),
            TextColumn::make('discount_type')->label('Discount type')->badge(),
            TextColumn::make('discount_value')->label(self::label('discount'))->numeric(decimalPlaces: 2),
            TextColumn::make('customer.name')->label(self::label('customer')),
            TextColumn::make('visibility')->label('Visibility')->badge(),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('valid_until')->label('Valid until')->date(),
            TextColumn::make('products_count')->label('Products')->numeric(),
            TextColumn::make('active_assignments_count')->label('Active customers')->numeric(),
            IconColumn::make('is_active')->label(self::label('active'))->boolean(),
        ];
    }

    /** @return array<int, TextColumn|IconColumn> */
    private static function customerAssignmentColumns(): array
    {
        return [
            TextColumn::make('customer.name')->label(self::label('customer'))->searchable(),
            TextColumn::make('pricingTier.name')->label(self::label('tier')),
            TextColumn::make('pricingTier.tier_type')->label('Type')->badge(),
            TextColumn::make('pricingTier.discount_type')->label('Discount type')->badge(),
            TextColumn::make('pricingTier.discount_value')->label(self::label('discount'))->numeric(decimalPlaces: 2),
            TextColumn::make('pricingTier.products.name')->label('Products')->listWithLineBreaks(),
            IconColumn::make('is_active')->label(self::label('active'))->boolean(),
            TextColumn::make('created_at')->label(self::label('date'))->dateTime(),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function floorOverrideColumns(): array
    {
        return [
            TextColumn::make('approved_at')->label(self::label('date'))->dateTime()->sortable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
            TextColumn::make('productVariant.name')->label(self::label('variant')),
            TextColumn::make('customer.name')->label(self::label('customer')),
            TextColumn::make('pricingTier.name')->label(self::label('tier')),
            TextColumn::make('attempted_price')->label(self::label('attempted_price'))->money('USD'),
            TextColumn::make('min_price')->label(self::label('min_price'))->money('USD'),
            TextColumn::make('approvedBy.name')->label(self::label('approved_by')),
            TextColumn::make('reason')->label(self::label('reason'))->limit(40),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function importRunColumns(): array
    {
        return [
            TextColumn::make('id')->label(self::label('import_run'))->sortable(),
            TextColumn::make('status')->label(self::label('status'))->badge(),
            TextColumn::make('total_rows')->label(self::label('total'))->numeric(),
            TextColumn::make('valid_rows')->label(self::label('valid'))->numeric(),
            TextColumn::make('applied_rows')->label(self::label('applied'))->numeric(),
            TextColumn::make('rejected_rows')->label(self::label('rejected'))->numeric(),
            TextColumn::make('createdBy.name')->label(self::label('created_by')),
            TextColumn::make('created_at')->label(self::label('date'))->dateTime()->sortable(),
        ];
    }

    /** @return array<int, TextColumn> */
    private static function importResultColumns(): array
    {
        return [
            TextColumn::make('run.id')->label(self::label('import_run')),
            TextColumn::make('row_number')->label(self::label('row'))->numeric()->sortable(),
            TextColumn::make('status')->label(self::label('status'))->badge(),
            TextColumn::make('operation')->label(self::label('operation')),
            TextColumn::make('errors')->label(self::label('validation_errors'))->formatStateUsing(self::json(...))->limit(50),
            TextColumn::make('runtime_error')->label(self::label('runtime_error'))->limit(50),
            TextColumn::make('result')->label(self::label('result'))->formatStateUsing(self::json(...))->limit(50),
            TextColumn::make('run.createdBy.name')->label(self::label('created_by')),
            TextColumn::make('created_at')->label(self::label('date'))->dateTime(),
        ];
    }

    private static function integerKey(SerializedInventoryUnit|InventoryMovement $record): int
    {
        $key = $record->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory report records must use integer identifiers.');
        }

        return $key;
    }

    private static function label(string $key): string
    {
        return __('admin.inventory.reports.columns.'.$key);
    }

    private static function json(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }
}
