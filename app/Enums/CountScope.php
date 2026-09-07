<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryCount;

/**
 * What a {@see InventoryCount} enumerates lines for (GAP-MW-06).
 *
 * Every scope is still bounded to a single warehouse (`inventory_counts.warehouse_id`);
 * this only narrows which variants/lots within that warehouse are in scope.
 */
enum CountScope: string
{
    case Warehouse = 'warehouse';
    case Category = 'category';
    case VariantSet = 'variant_set';
    case Lot = 'lot';

    public function requiresProductCategory(): bool
    {
        return $this === self::Category;
    }

    public function requiresInventoryLot(): bool
    {
        return $this === self::Lot;
    }

    public function requiresVariantSet(): bool
    {
        return $this === self::VariantSet;
    }
}
