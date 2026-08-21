<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
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
            ->with('warehouse:id,code,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $events = $movements
            ->map(fn (InventoryMovement $movement): array => $this->movementEvent($movement))
            ->values()
            ->all();

        if (! $movements->contains('movement_type', MovementType::Receipt)) {
            $receiptEvent = $this->receiptEvent($unit);

            if ($receiptEvent !== null) {
                $events[] = $receiptEvent;
            }
        }

        usort($events, static fn (array $left, array $right): int => [
            $left['occurred_at'],
            $left['sequence'],
        ] <=> [
            $right['occurred_at'],
            $right['sequence'],
        ]);

        return $events;
    }

    /** @return array{occurred_at: string, type: string, warehouse: string, quantity: string, source: string, notes: string|null, synthetic: bool, sequence: int} */
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
            'source' => $this->sourceLabel($movement->source_type, $movement->source_id),
            'notes' => $movement->notes,
            'synthetic' => false,
            'sequence' => $this->integerKey($movement->getKey()),
        ];
    }

    /** @return array{occurred_at: string, type: string, warehouse: string, quantity: string, source: string, notes: string|null, synthetic: bool, sequence: int}|null */
    private function receiptEvent(SerializedInventoryUnit $unit): ?array
    {
        $item = $unit->receiptItem()->with('receipt.warehouse')->first();
        $receipt = $item?->receipt;

        if (! $item instanceof InventoryReceiptItem || ! $receipt instanceof InventoryReceipt) {
            return null;
        }

        $occurredAt = $item->created_at ?? $receipt->created_at;

        if ($occurredAt === null) {
            throw new LogicException('A persisted inventory receipt must have a creation timestamp.');
        }

        return [
            'occurred_at' => $occurredAt->toIso8601String(),
            'type' => MovementType::Receipt->value,
            'warehouse' => $this->warehouseLabel($receipt->warehouse),
            'quantity' => '1.000',
            'source' => 'receipt '.($receipt->receipt_number ?? '#'.$this->integerKey($receipt->getKey())),
            'notes' => $receipt->supplier_reference,
            'synthetic' => true,
            'sequence' => 0,
        ];
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
