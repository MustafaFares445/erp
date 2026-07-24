<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Models\ProductVariant;
use LogicException;
use Spatie\LaravelData\Data;

final class InventoryImportRowResult extends Data
{
    public ?int $productId = null;

    public ?int $productVariantId = null;

    public ?int $inventoryReceiptId = null;

    public ?int $inventoryReceiptItemId = null;

    public ?int $serializedInventoryUnitId = null;

    public ?int $inventoryLotId = null;

    public ?string $catalogOperation = null;

    public static function forVariant(ProductVariant $variant, string $catalogOperation): self
    {
        $result = new self;
        $result->productId = self::integerKey($variant->product_id);
        $result->productVariantId = self::integerKey($variant->getKey());
        $result->catalogOperation = $catalogOperation;

        return $result;
    }

    /** @return array<string, int|string> */
    public function values(): array
    {
        return array_filter([
            'product_id' => $this->productId,
            'product_variant_id' => $this->productVariantId,
            'inventory_receipt_id' => $this->inventoryReceiptId,
            'inventory_receipt_item_id' => $this->inventoryReceiptItemId,
            'serialized_inventory_unit_id' => $this->serializedInventoryUnitId,
            'inventory_lot_id' => $this->inventoryLotId,
            'catalog_operation' => $this->catalogOperation,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    private static function integerKey(mixed $key): int
    {
        if (! is_int($key)) {
            throw new LogicException('Imported inventory entities must use integer identifiers.');
        }

        return $key;
    }
}
