<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\ConditionChangeReason;
use App\Enums\StockCondition;
use App\Services\Inventory\InventoryAdjustmentService;
use Spatie\LaravelData\Data;

/**
 * Single source of truth for stock-adjustment draft validation rules (plan
 * §2.5), shared by the Filament form/relation manager and, in the future,
 * an API Form Request. Matches the {@see WarehouseData} precedent.
 *
 * `old_quantity` and `difference` are intentionally absent: they are never
 * user input (FR-004/FR-007) — derived/finalized only by
 * {@see InventoryAdjustmentService::confirm()}.
 *
 * @see /specs/003-stock-adjustments/contracts/adjustment-resource.md
 */
final class AdjustmentData extends Data
{
    public function __construct(
        public int $warehouse_id,
        public string $reason,
        public ConditionChangeReason $reason_category,
        /** @var array<int, array{product_variant_id: int, stock_condition: StockCondition, new_quantity: float}> */
        public array $items,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'reason_category' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (ConditionChangeReason $reason): string => $reason->value,
                ConditionChangeReason::cases(),
            ))],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.stock_condition' => ['required', 'string', 'in:'.implode(',', [
                StockCondition::Saleable->value,
                StockCondition::Quarantine->value,
                StockCondition::Damaged->value,
            ])],
            'items.*.new_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
