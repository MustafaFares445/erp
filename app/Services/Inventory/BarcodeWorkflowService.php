<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\BarcodeResolution;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final readonly class BarcodeWorkflowService
{
    public function __construct(private InventoryCountService $counts) {}

    /** @return Collection<int, InventoryOperationLine> */
    public function operationMatches(InventoryOperation $operation, BarcodeResolution $resolution): Collection
    {
        return $operation->lines()
            ->with(['productVariant', 'serializedUnit'])
            ->where('product_variant_id', $resolution->productVariantId)
            ->when(
                $resolution->serializedInventoryUnitId !== null,
                fn ($query) => $query->where('serialized_inventory_unit_id', $resolution->serializedInventoryUnitId),
            )
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, InventoryCountLine> */
    public function countMatches(InventoryCount $count, BarcodeResolution $resolution): Collection
    {
        return $count->lines()
            ->with(['productVariant', 'serializedUnit', 'lot'])
            ->where('product_variant_id', $resolution->productVariantId)
            ->when(
                $resolution->serializedInventoryUnitId !== null,
                fn ($query) => $query->where('serialized_inventory_unit_id', $resolution->serializedInventoryUnitId),
            )
            ->orderBy('id')
            ->get();
    }

    public function recordCount(
        User $actor,
        InventoryCount $count,
        InventoryCountLine $line,
        BarcodeResolution $resolution,
        string $quantity,
    ): InventoryCountLine {
        if ($line->inventory_count_id !== $count->getKey()) {
            throw new DomainException('The selected count line does not belong to this inventory count.');
        }
        if ($line->product_variant_id !== $resolution->productVariantId) {
            throw new DomainException('The scanned item does not match the selected count line.');
        }
        if ($resolution->serializedInventoryUnitId !== null
            && $line->serialized_inventory_unit_id !== $resolution->serializedInventoryUnitId) {
            throw new DomainException('The scanned serial does not match the selected count line.');
        }

        return $this->counts->recordCount($line, $quantity, $actor);
    }
}
