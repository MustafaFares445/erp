<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final readonly class SerializedInventoryTimelineService
{
    /**
     * @return list<array{
     *     occurred_at: string,
     *     type: string,
     *     warehouse: string,
     *     quantity: string,
     *     transaction_quantity: string|null,
     *     transaction_unit: string|null,
     *     base_quantity_delta: string,
     *     lot: string|null,
     *     source_line: string|null,
     *     reversal_of: int|null,
     *     condition_from: string|null,
     *     condition_to: string|null,
     *     source: string,
     *     notes: string|null,
     *     synthetic: bool,
     *     sequence: int
     * }>
     */
    public function events(SerializedInventoryUnit $unit): array
    {
        /** @var Collection<int, InventoryMovement> $movements */
        $movements = $unit->movements()
            ->with([
                'warehouse:id,code,name',
                'transactionUnit:id,symbol',
                'lot:id,lot_number',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return array_values(
            $movements
                ->map(fn (InventoryMovement $movement): array => $this->movementEvent($movement))
                ->all(),
        );
    }

    /** @return array{occurred_at: string, type: string, warehouse: string, quantity: string, transaction_quantity: string|null, transaction_unit: string|null, base_quantity_delta: string, lot: string|null, source_line: string|null, reversal_of: int|null, condition_from: string|null, condition_to: string|null, source: string, notes: string|null, synthetic: bool, sequence: int} */
    private function movementEvent(InventoryMovement $movement): array
    {
        if ($movement->created_at === null) {
            throw new LogicException('A persisted inventory movement must have a creation timestamp.');
        }

        return [
            'occurred_at' => $movement->created_at->toIso8601String(),
            'type' => $movement->movement_type->value,
            'warehouse' => $this->warehouseLabel($movement->warehouse),
            'quantity' => number_format((float) $movement->quantity, 3, '.', ''),
            'transaction_quantity' => $movement->transaction_quantity === null
                ? null
                : (string) $movement->transaction_quantity,
            'transaction_unit' => $movement->transactionUnit?->symbol,
            'base_quantity_delta' => (string) ($movement->base_quantity_delta ?? $movement->quantity),
            'lot' => $movement->lot?->lot_number,
            'source_line' => $movement->source_line_type === null
                ? null
                : sprintf('%s #%s', $movement->source_line_type, $movement->source_line_id ?? '—'),
            'reversal_of' => $movement->reversal_of_movement_id,
            'condition_from' => $movement->stock_condition_from?->value,
            'condition_to' => $movement->stock_condition_to?->value,
            'source' => $this->sourceLabel($movement->source_type, $movement->source_id),
            'notes' => $movement->notes,
            'synthetic' => false,
            'sequence' => $this->integerKey($movement->getKey()),
        ];
    }

    public function receiptSource(SerializedInventoryUnit $unit): ?string
    {
        $movement = $unit->receiptMovement;

        if (! $movement instanceof InventoryMovement) {
            return null;
        }

        return $this->sourceLabel($movement->source_type, $movement->source_id);
    }

    private function warehouseLabel(?Warehouse $warehouse): string
    {
        if (! $warehouse instanceof Warehouse) {
            return 'No warehouse';
        }

        return sprintf('%s - %s', $warehouse->code, $warehouse->name);
    }

    private function sourceLabel(?string $type, mixed $id): string
    {
        if ($type === null) {
            return 'Manual';
        }

        if (is_int($id) || is_string($id)) {
            return sprintf('%s #%s', $type, $id);
        }

        return $type;
    }

    private function integerKey(mixed $key): int
    {
        return is_int($key) ? $key : 0;
    }
}
