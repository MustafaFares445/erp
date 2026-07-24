<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Services\Inventory\StockTransferService;
use Spatie\LaravelData\Data;

/**
 * Single source of truth for stock-transfer draft validation rules (plan
 * §2.5), shared by the Filament form/relation manager and, in the future, an
 * API Form Request. Matches the {@see AdjustmentData} precedent.
 *
 * `transfer_number` is intentionally absent: it is never user input
 * (FR-003) — assigned only by {@see StockTransferService::confirm()}.
 *
 * @see /specs/004-stock-transfers/contracts/transfer-resource.md
 */
final class TransferData extends Data
{
    public function __construct(
        public int $from_warehouse_id,
        public int $to_warehouse_id,
        public ?string $notes,
        /** @var array<int, array{product_variant_id: int, quantity: float}> */
        public array $items,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
