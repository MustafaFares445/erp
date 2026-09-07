<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\CountScope;
use App\Enums\StockCondition;
use Spatie\LaravelData\Data;

final class CountScopeData extends Data
{
    /**
     * @param  list<string>  $conditions  {@see StockCondition} values in scope; empty means every materialized condition.
     * @param  list<int>|null  $productVariantIds  Only used/required for {@see CountScope::VariantSet}; not persisted beyond the lines it generates.
     */
    public function __construct(
        public int $warehouseId,
        public CountScope $scopeType,
        public ?int $productCategoryId,
        public ?int $inventoryLotId,
        public ?array $productVariantIds,
        public array $conditions,
        public ?int $materialityThresholdMinor,
    ) {}
}
