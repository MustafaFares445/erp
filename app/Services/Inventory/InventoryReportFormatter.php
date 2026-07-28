<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryReportType;
use App\Models\CustomerPricingTier;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
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
                'SKU', 'Variant', 'Product', 'Brand', 'Category', 'Unit', 'Barcode',
                'Status', 'Active', 'Supplier references',
                ...($includePricing ? ['Cost', 'Base price', 'Minimum price', 'Markup percent'] : []),
            ],
            InventoryReportType::StockLevels => [
                'SKU', 'Variant', 'Warehouse', 'On hand', 'Reserved', 'Damaged', 'Available', 'Reorder level', 'In transit',
                ...($includePricing ? ['Cost', 'Usable value'] : []),
            ],
            InventoryReportType::Movements => ['Date', 'SKU', 'Variant', 'Warehouse', 'Type', 'Quantity', 'Serial', 'IoT', 'Source type', 'Source ID'],
            InventoryReportType::Devices => ['Serial', 'IoT', 'SKU', 'Variant', 'Status', 'Warehouse', 'Receipt', 'Movement count'],
            InventoryReportType::ExpiryLots => ['Lot', 'SKU', 'Variant', 'Warehouse', 'Expiry', 'Days remaining', 'On hand', 'Reserved', 'Available', 'State'],
            InventoryReportType::SupplierComparison => ['Supplier', 'Supplier code', 'SKU', 'Variant', 'Supplier item', 'Manufacturer', 'Country', 'Purchase price', 'Currency', 'Active'],
            InventoryReportType::PriceHistory => ['Date', 'SKU', 'Variant', 'Cost', 'Base price', 'Minimum price', 'Markup percent', 'Changed by'],
            InventoryReportType::PricingTiers => ['Tier', 'Discount percent', 'Specific customer', 'Active', 'Assignment count'],
            InventoryReportType::CustomerAssignments => ['Customer', 'Tier', 'Discount percent', 'Active', 'Assigned at'],
            InventoryReportType::FloorOverrides => ['Approved at', 'SKU', 'Variant', 'Customer', 'Attempted price', 'Minimum price', 'Approved by', 'Reason'],
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
            $product?->brand?->name,
            $product?->category?->name,
            $record->unit?->symbol,
            $record->barcode,
            $this->enum($record->status),
            $record->is_active,
            (int) $record->supplier_references_count,
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
            $record->warehouse?->name,
            $this->decimal($record->on_hand_quantity),
            $this->decimal($record->reserved_quantity),
            $this->decimal($record->damaged_quantity),
            $this->decimal($record->available_quantity),
            $this->decimal($record->reorder_level),
            $record->inTransitQuantity(),
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
            $record->serializedUnit?->serial_number,
            $record->serializedUnit?->iot_number,
            $record->source_type,
            $this->integer($record->source_id),
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function device(Model $record): array
    {
        if (! $record instanceof SerializedInventoryUnit) {
            throw $this->invalidRecord(InventoryReportType::Devices);
        }

        return [
            $record->serial_number,
            $record->iot_number,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $this->enum($record->status),
            $record->warehouse?->name,
            $record->receiptItem?->receipt?->receipt_number,
            (int) $record->movements_count,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function expiryLot(Model $record): array
    {
        if (! $record instanceof InventoryLot) {
            throw $this->invalidRecord(InventoryReportType::ExpiryLots);
        }

        return [
            $record->lot_number,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $record->warehouse?->name,
            $this->date($record->expires_at),
            $record->daysRemaining(),
            $this->decimal($record->on_hand_quantity),
            $this->decimal($record->reserved_quantity),
            $record->availableQuantity(),
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
            $this->decimal($record->discount_percent),
            $record->customer?->name,
            $record->is_active,
            (int) $record->assignments_count,
        ];
    }

    /** @return list<bool|float|int|string|null> */
    private function customerAssignment(Model $record): array
    {
        if (! $record instanceof CustomerPricingTier) {
            throw $this->invalidRecord(InventoryReportType::CustomerAssignments);
        }

        return [
            $record->customer?->name,
            $record->pricingTier?->name,
            $this->decimal($record->pricingTier?->discount_percent),
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
