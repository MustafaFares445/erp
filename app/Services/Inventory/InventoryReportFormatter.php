<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryReportType;
use App\Enums\StockCondition;
use App\Models\CustomerPricingTier;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierProductReference;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class InventoryReportFormatter
{
    /** @return list<string> */
    public function headings(InventoryReportType $type, bool $includePricing): array
    {
        return match ($type) {
            InventoryReportType::Catalog => [
                'SKU', 'Variant', 'Product', 'Product type', 'Brand', 'Category', 'Unit', 'Barcode',
                'Status', 'Active', 'Supplier references', 'Net weight', 'Weight unit',
                ...($includePricing ? ['Cost', 'Base price', 'Minimum price', 'Markup percent'] : []),
            ],
            InventoryReportType::StockLevels => [
                'SKU', 'Variant', 'Product type', 'Warehouse', 'On hand', 'Reserved', 'Damaged', 'Available', 'Reorder level', 'In transit',
                'Total weight', 'Weight unit',
                ...($includePricing ? ['Cost', 'Usable value'] : []),
            ],
            InventoryReportType::Movements => [
                'Date',
                'SKU',
                'Variant',
                'Warehouse',
                'Type',
                'Ledger quantity',
                'Transaction quantity',
                'Transaction unit',
                'Conversion factor',
                'Base quantity delta',
                'Condition from',
                'From on-hand before',
                'From on-hand after',
                'From reserved before',
                'From reserved after',
                'Condition to',
                'To on-hand before',
                'To on-hand after',
                'To reserved before',
                'To reserved after',
                'Lot',
                'Serial',
                'IoT',
                'Package',
                'Source type',
                'Source ID',
                'Source line type',
                'Source line ID',
                'Reversal movement ID',
            ],
            InventoryReportType::Devices => ['Serial', 'IoT', 'SKU', 'Variant', 'Status', 'Warehouse', 'Receipt source', 'Movement count'],
            InventoryReportType::ExpiryLots => [
                'Lot', 'SKU', 'Variant', 'Warehouses', 'Expiry', 'Days remaining',
                'On hand', 'Saleable', 'Quarantine', 'Damaged', 'Reserved', 'Available', 'State',
            ],
            InventoryReportType::SupplierComparison => ['Supplier', 'Supplier code', 'SKU', 'Variant', 'Supplier item', 'Manufacturer', 'Country', 'Purchase price', 'Currency', 'Active'],
            InventoryReportType::PriceHistory => ['Date', 'SKU', 'Variant', 'Cost', 'Base price', 'Minimum price', 'Markup percent', 'Changed by'],
            InventoryReportType::PricingTiers => ['Tier', 'Type', 'Discount type', 'Discount value', 'Specific customer', 'Visibility', 'Status', 'Valid from', 'Valid until', 'Products', 'Active customers', 'Active'],
            InventoryReportType::CustomerAssignments => ['Customer', 'Tier', 'Type', 'Discount type', 'Discount value', 'Products', 'Active', 'Assigned at'],
            InventoryReportType::FloorOverrides => ['Approved at', 'SKU', 'Variant', 'Customer', 'Pricing tier', 'Attempted price', 'Minimum price', 'Approved by', 'Reason'],
            InventoryReportType::ImportRuns => ['Run', 'Status', 'Total', 'Valid', 'Failed', 'Created', 'Updated', 'Applied', 'Rejected', 'Created by', 'Confirmed by', 'Created at'],
            InventoryReportType::ImportResults => ['Run', 'Row', 'Status', 'Operation', 'Validation errors', 'Runtime error', 'Affected records', 'Payload', 'Created by', 'Created at'],
        };
    }

    /**
     * @return list<bool|float|int|string|null>
     */
    public function values(InventoryReportType $type, Model $record, bool $includePricing): array
    {
        return match ($type) {
            InventoryReportType::Catalog => $this->catalog($record, $includePricing),
            InventoryReportType::StockLevels => $this->stock($record, $includePricing),
            InventoryReportType::Movements => $this->movement($record),
            InventoryReportType::Devices => $this->device($record),
            InventoryReportType::ExpiryLots => $this->expiryLot($record),
            InventoryReportType::SupplierComparison => $this->supplier($record),
            InventoryReportType::PriceHistory => $this->priceHistory($record),
            InventoryReportType::PricingTiers => $this->pricingTier($record),
            InventoryReportType::CustomerAssignments => $this->customerAssignment($record),
            InventoryReportType::FloorOverrides => $this->floorOverride($record),
            InventoryReportType::ImportRuns => $this->importRun($record),
            InventoryReportType::ImportResults => $this->importResult($record),
        };
    }

    /** @return list<bool|float|int|string|null> */
    private function catalog(Model $record, bool $includePricing): array
    {
        if (! $record instanceof ProductVariant) {
            throw $this->invalidRecord(InventoryReportType::Catalog);
        }

        $product = $record->product;
        $values = [
            $record->sku,
            $record->name,
            $product?->name,
            $product?->product_type?->label(),
            $product?->brand?->name,
            $product?->category?->name,
            $record->unit?->symbol,
            $record->barcode,
            $this->enum($record->status),
            $record->is_active,
            (int) $record->supplier_references_count,
            $this->decimal($record->net_weight),
            $record->weightUnit?->symbol,
        ];

        if ($includePricing) {
            return [
                ...$values,
                $this->decimal($record->cost_price),
                $this->decimal($record->base_price),
                $this->decimal($record->min_price),
                $this->decimal($record->markup_percent),
            ];
        }

        return $values;
    }

    /** @return list<bool|float|int|string|null> */
    private function stock(Model $record, bool $includePricing): array
    {
        if (! $record instanceof InventoryStock) {
            throw $this->invalidRecord(InventoryReportType::StockLevels);
        }

        $variant = $record->productVariant;
        $cost = $this->decimal($variant?->cost_price);
        $values = [
            $variant?->sku,
            $variant?->name,
            $variant?->productType()?->label(),
            $record->warehouse?->name,
            $this->decimal($record->on_hand_quantity),
            $this->decimal($record->reserved_quantity),
            $this->decimal($record->damaged_quantity),
            $this->decimal($record->available_quantity),
            $this->decimal($record->reorder_level),
            $record->inTransitQuantity(),
            // Null rather than zero for anything not weighed, so a blank cell means "no weight
            // applies here" instead of "weighs nothing".
            $variant?->weightFor($this->decimal($record->on_hand_quantity) ?? 0.0),
            $variant?->weightUnit?->symbol,
        ];

        if ($includePricing) {
            $values[] = $cost;
            $values[] = $cost === null ? 0.0 : $this->decimal($record->available_quantity) * $cost;
        }

        return $values;
    }

    /** @return list<bool|float|int|string|null> */
    private function movement(Model $record): array
    {
        if (! $record instanceof InventoryMovement) {
            throw $this->invalidRecord(InventoryReportType::Movements);
        }

        return [
            $this->date($record->created_at),
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $record->warehouse?->name,
            $this->enum($record->movement_type),
            $this->decimal($record->quantity),
            $this->decimal($record->transaction_quantity),
            $record->transactionUnit?->symbol,
            $this->decimal($record->conversion_factor_snapshot),
            $this->decimal($record->base_quantity_delta),
            $this->enum($record->stock_condition_from),
            $this->decimal($record->condition_from_on_hand_before),
            $this->decimal($record->condition_from_on_hand_after),
            $this->decimal($record->condition_from_reserved_before),
            $this->decimal($record->condition_from_reserved_after),
            $this->enum($record->stock_condition_to),
            $this->decimal($record->condition_to_on_hand_before),
            $this->decimal($record->condition_to_on_hand_after),
            $this->decimal($record->condition_to_reserved_before),
            $this->decimal($record->condition_to_reserved_after),
            $record->lot?->lot_number,
            $record->serializedUnit?->serial_number,
            $record->serializedUnit?->iot_number,
            $record->package?->name,
            $record->source_type,
            $this->integer($record->source_id),
            $record->source_line_type,
            $this->integer($record->source_line_id),
            $this->integer($record->reversal_of_movement_id),
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function device(Model $record): array
    {
        if (! $record instanceof SerializedInventoryUnit) {
            throw $this->invalidRecord(InventoryReportType::Devices);
        }

        $receiptMovement = $record->receiptMovement;
        $receiptSource = $receiptMovement instanceof InventoryMovement
            ? sprintf(
                '%s #%s',
                $receiptMovement->source_type ?? 'inventory_movement',
                $receiptMovement->source_id ?? $receiptMovement->getKey(),
            )
            : null;

        return [
            $record->serial_number,
            $record->iot_number,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $this->enum($record->status),
            $record->warehouse?->name,
            $receiptSource,
            (int) $record->movements_count,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function expiryLot(Model $record): array
    {
        if (! $record instanceof InventoryLot) {
            throw $this->invalidRecord(InventoryReportType::ExpiryLots);
        }

        $warehouses = $record->conditionBalances
            ->filter(fn (InventoryLotBalance $balance): bool => (float) $balance->on_hand_base_quantity > 0)
            ->pluck('warehouse.code')
            ->filter()
            ->unique()
            ->sort()
            ->implode(', ');

        return [
            $record->lot_number,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $warehouses === '' ? null : $warehouses,
            $this->date($record->expires_at),
            $record->daysRemaining(),
            $record->totalPhysicalQuantity(),
            $record->totalConditionOnHandQuantity(StockCondition::Saleable),
            $record->totalConditionOnHandQuantity(StockCondition::Quarantine),
            $record->totalConditionOnHandQuantity(StockCondition::Damaged),
            $record->totalConditionReservedQuantity(StockCondition::Saleable),
            $record->totalAvailableQuantity(),
            $record->expiryState(),
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function supplier(Model $record): array
    {
        if (! $record instanceof SupplierProductReference) {
            throw $this->invalidRecord(InventoryReportType::SupplierComparison);
        }

        return [
            $record->supplier?->name,
            $record->supplier?->code,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $record->supplier_item_number,
            $record->manufacturer,
            $record->country_code,
            $this->decimal($record->purchase_cost),
            $record->currency_code,
            $record->is_active,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function priceHistory(Model $record): array
    {
        if (! $record instanceof PriceHistory) {
            throw $this->invalidRecord(InventoryReportType::PriceHistory);
        }

        return [
            $this->date($record->created_at),
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $this->decimal($record->cost_price),
            $this->decimal($record->base_price),
            $this->decimal($record->min_price),
            $this->decimal($record->markup_percent),
            $record->changedBy?->name,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function pricingTier(Model $record): array
    {
        if (! $record instanceof PricingTier) {
            throw $this->invalidRecord(InventoryReportType::PricingTiers);
        }

        return [
            $record->name,
            $this->enum($record->tier_type),
            $this->enum($record->discount_type),
            $this->decimal($record->discount_value),
            $record->customer?->name,
            $this->enum($record->visibility),
            $record->status(),
            $this->date($record->valid_from),
            $this->date($record->valid_until),
            $this->integer($record->getAttribute('products_count')) ?? 0,
            $this->integer($record->getAttribute('active_assignments_count')) ?? 0,
            $record->is_active,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function customerAssignment(Model $record): array
    {
        if (! $record instanceof CustomerPricingTier) {
            throw $this->invalidRecord(InventoryReportType::CustomerAssignments);
        }

        $tier = $record->pricingTier;
        $productNames = $tier?->products
            ->map(static fn (Product $product): string => $product->name)
            ->implode(', ') ?? '';

        return [
            $record->customer?->name,
            $tier?->name,
            $this->enum($tier?->tier_type),
            $this->enum($tier?->discount_type),
            $this->decimal($tier?->discount_value),
            $productNames,
            $record->is_active,
            $this->date($record->created_at),
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function floorOverride(Model $record): array
    {
        if (! $record instanceof PriceFloorOverride) {
            throw $this->invalidRecord(InventoryReportType::FloorOverrides);
        }

        return [
            $this->date($record->approved_at),
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $record->customer?->name,
            $record->pricingTier?->name,
            $this->decimal($record->attempted_price),
            $this->decimal($record->min_price),
            $record->approvedBy?->name,
            $record->reason,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function importRun(Model $record): array
    {
        if (! $record instanceof InventoryImportRun) {
            throw $this->invalidRecord(InventoryReportType::ImportRuns);
        }

        return [
            $this->integer($record->getKey()),
            $this->enum($record->status),
            $this->integer($record->total_rows),
            $this->integer($record->valid_rows),
            $this->integer($record->failed_rows),
            $this->integer($record->created_rows),
            $this->integer($record->updated_rows),
            $this->integer($record->applied_rows),
            $this->integer($record->rejected_rows),
            $record->createdBy?->name,
            $record->confirmedBy?->name,
            $this->date($record->created_at),
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function importResult(Model $record): array
    {
        if (! $record instanceof InventoryImportItem) {
            throw $this->invalidRecord(InventoryReportType::ImportResults);
        }

        return [
            $this->integer($record->inventory_import_run_id),
            $this->integer($record->row_number),
            $this->enum($record->status),
            $record->operation,
            $this->json($record->errors),
            $record->runtime_error,
            $this->json($record->result),
            $this->json($record->payload),
            $record->run?->createdBy?->name,
            $this->date($record->created_at),
        ];
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }

    private function enum(mixed $value): ?string
    {
        return $value instanceof BackedEnum && is_string($value->value) ? $value->value : null;
    }

    private function json(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new LogicException('Unable to encode an inventory report value.');
        }

        return $encoded;
    }

    private function invalidRecord(InventoryReportType $type): LogicException
    {
        return new LogicException(sprintf('Invalid model supplied for the %s report.', $type->value));
    }
}
