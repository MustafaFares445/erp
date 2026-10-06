<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final readonly class InventoryLotTimelineService
{
    /**
     * @return list<array{
     *     occurred_at: string,
     *     movement: string,
     *     warehouse: string,
     *     base_quantity_delta: string,
     *     transaction_quantity: string|null,
     *     transaction_unit: string|null,
     *     condition_from: string|null,
     *     condition_to: string|null,
     *     serial: string|null,
     *     source: string,
     *     counterparty: string|null,
     *     invoice: string|null,
     *     notes: string|null
     * }>
     */
    public function events(InventoryLot $lot): array
    {
        /** @var Collection<int, InventoryMovement> $movements */
        $movements = $lot->movements()
            ->with([
                'warehouse:id,code,name',
                'transactionUnit:id,symbol',
                'serializedUnit:id,serial_number',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $operationIds = $movements
            ->where('source_type', 'inventory_operation')
            ->pluck('source_id')
            ->filter(static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $returnIds = $movements
            ->where('source_type', 'inventory_return')
            ->pluck('source_id')
            ->filter(static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $operations = InventoryOperation::query()
            ->whereIn('id', $operationIds)
            ->with([
                'supplier:id,name',
                'customer:id,company_name',
                'invoiceDeliveryLink.invoice:id,invoice_number',
            ])
            ->get()
            ->keyBy('id');

        $returns = InventoryReturn::query()
            ->whereIn('id', $returnIds)
            ->with(['supplier:id,name', 'customer:id,company_name'])
            ->get()
            ->keyBy('id');

        return array_values($movements
            ->map(function (InventoryMovement $movement) use ($operations, $returns): array {
                if ($movement->created_at === null) {
                    throw new LogicException('A persisted inventory movement must have a creation timestamp.');
                }

                $operation = $movement->source_type === 'inventory_operation' && is_numeric($movement->source_id)
                    ? $operations->get((int) $movement->source_id)
                    : null;
                $return = $movement->source_type === 'inventory_return' && is_numeric($movement->source_id)
                    ? $returns->get((int) $movement->source_id)
                    : null;

                return [
                    'occurred_at' => $movement->created_at->toIso8601String(),
                    'movement' => $movement->movement_type->value,
                    'warehouse' => $movement->warehouse === null
                        ? 'No warehouse'
                        : $movement->warehouse->code.' - '.$movement->warehouse->name,
                    'base_quantity_delta' => (string) ($movement->base_quantity_delta ?? $movement->quantity),
                    'transaction_quantity' => $movement->transaction_quantity === null
                        ? null
                        : (string) $movement->transaction_quantity,
                    'transaction_unit' => $movement->transactionUnit?->symbol,
                    'condition_from' => $movement->stock_condition_from?->value,
                    'condition_to' => $movement->stock_condition_to?->value,
                    'serial' => $movement->serializedUnit?->serial_number,
                    'source' => $this->sourceLabel($movement, $operation, $return),
                    'counterparty' => $operation?->customer?->company_name
                        ?? $operation?->supplier?->name
                        ?? $return?->customer?->company_name
                        ?? $return?->supplier?->name,
                    'invoice' => $operation?->invoiceDeliveryLink?->invoice?->invoice_number,
                    'notes' => $movement->notes,
                ];
            })
            ->all());
    }

    private function sourceLabel(
        InventoryMovement $movement,
        ?InventoryOperation $operation,
        ?InventoryReturn $return,
    ): string {
        if ($operation instanceof InventoryOperation) {
            $reference = is_string($operation->operation_number) && $operation->operation_number !== ''
                ? $operation->operation_number
                : '#'.$operation->getKey();

            return $operation->operation_type->label().' '.$reference;
        }

        if ($return instanceof InventoryReturn) {
            return 'Inventory Return '.($return->return_number ?? '#'.$return->getKey());
        }

        if ($movement->source_type === null) {
            return 'Manual';
        }

        return $movement->source_type.' #'.($movement->source_id ?? '—');
    }
}
