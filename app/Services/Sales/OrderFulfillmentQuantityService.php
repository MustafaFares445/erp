<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderFulfillmentLineProgress;
use App\Enums\InventoryReturnStatus;
use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturnLine;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use Illuminate\Support\Collection;

final class OrderFulfillmentQuantityService
{
    /**
     * @return Collection<int, OrderFulfillmentLineProgress>
     */
    public function forOrder(Order $order): Collection
    {
        $lines = $order->lines()->orderBy('id')->get();
        $lineIds = $lines->modelKeys();

        if ($lineIds === []) {
            return collect();
        }

        $deliveries = $order->deliveries()
            ->with('lines')
            ->orderBy('id')
            ->get();

        $shipmentsByDelivery = $order->shipments()
            ->get()
            ->keyBy('inventory_operation_id');

        $deliveryLines = $deliveries->flatMap(
            static fn (InventoryOperation $operation) => $operation->lines,
        );
        $deliveryLineIds = $deliveryLines->modelKeys();

        $returnedByDeliveryLine = $deliveryLineIds === []
            ? collect()
            : InventoryReturnLine::query()
                ->whereIn('original_inventory_operation_line_id', $deliveryLineIds)
                ->whereHas('inventoryReturn', fn ($query) => $query->where('status', InventoryReturnStatus::Posted->value))
                ->get(['original_inventory_operation_line_id', 'posted_base_quantity', 'base_quantity'])
                ->groupBy('original_inventory_operation_line_id')
                ->map(fn (Collection $rows): float => round((float) $rows->sum(
                    fn (InventoryReturnLine $row): float => (float) ($row->posted_base_quantity ?? $row->base_quantity),
                ), 6));

        $invoiceLines = InvoiceLine::query()
            ->whereIn('order_line_id', $lineIds)
            ->whereHas('invoice', fn ($query) => $query->where('order_id', $order->getKey()))
            ->get(['order_line_id', 'quantity']);

        $invoiceByOrderLine = $invoiceLines
            ->groupBy('order_line_id')
            ->map(fn (Collection $rows): float => (float) $rows->sum('quantity'));

        $deliveryLinesByOrderLine = $deliveryLines
            ->filter(static fn (InventoryOperationLine $line): bool => $line->order_line_id !== null)
            ->groupBy('order_line_id');

        return $lines->mapWithKeys(function (OrderLine $line) use (
            $deliveries,
            $shipmentsByDelivery,
            $deliveryLinesByOrderLine,
            $returnedByDeliveryLine,
            $invoiceByOrderLine,
        ): array {
            $ordered = (float) ($line->base_quantity ?? 0);
            $shortClosed = (float) $line->short_closed_base_quantity;
            /** @var Collection<int, InventoryOperationLine> $linkedLines */
            $linkedLines = $deliveryLinesByOrderLine->get($line->id, collect());

            $planned = 0.0;
            $reserved = 0.0;
            $ready = 0.0;
            $dispatched = 0.0;
            $arrived = 0.0;
            $returned = 0.0;

            foreach ($linkedLines as $deliveryLine) {
                $operation = $deliveries->firstWhere('id', $deliveryLine->inventory_operation_id);
                if (! $operation instanceof InventoryOperation) {
                    continue;
                }
                if ($operation->stage === OperationStage::Canceled) {
                    continue;
                }

                $quantity = $this->baseQuantity($deliveryLine);
                $planned += $quantity;

                if ($operation->stage === OperationStage::Ready) {
                    $reserved += $quantity;
                    $ready += $quantity;
                }

                if ($operation->stage === OperationStage::Done) {
                    $dispatched += $quantity;
                    $shipment = $shipmentsByDelivery->get($operation->id);
                    if ($shipment instanceof Shipment && $shipment->status === ShipmentStatus::Arrived) {
                        $arrived += $quantity;
                    }
                }

                $returned += (float) $returnedByDeliveryLine->get($deliveryLine->id, 0.0);
            }

            $factor = (float) ($line->conversion_factor_snapshot ?? 1);
            $invoiced = ((float) $invoiceByOrderLine->get($line->id, 0.0)) * max($factor, 0.000001);
            $remaining = max(0.0, $ordered - $shortClosed - $planned);

            return [$line->id => new OrderFulfillmentLineProgress(
                orderLineId: $line->id,
                productVariantId: (int) $line->product_variant_id,
                orderedBase: round($ordered, 6),
                shortClosedBase: round($shortClosed, 6),
                plannedBase: round($planned, 6),
                reservedBase: round($reserved, 6),
                readyBase: round($ready, 6),
                dispatchedBase: round($dispatched, 6),
                arrivedBase: round($arrived, 6),
                returnedBase: round($returned, 6),
                invoicedBase: round($invoiced, 6),
                remainingToPlanBase: round($remaining, 6),
            )];
        });
    }

    /** @return array{ordered:float,short_closed:float,planned:float,reserved:float,ready:float,dispatched:float,arrived:float,returned:float,invoiced:float,remaining:float} */
    public function totals(Order $order): array
    {
        $lines = $this->forOrder($order);

        return [
            'ordered' => round((float) $lines->sum('orderedBase'), 6),
            'short_closed' => round((float) $lines->sum('shortClosedBase'), 6),
            'planned' => round((float) $lines->sum('plannedBase'), 6),
            'reserved' => round((float) $lines->sum('reservedBase'), 6),
            'ready' => round((float) $lines->sum('readyBase'), 6),
            'dispatched' => round((float) $lines->sum('dispatchedBase'), 6),
            'arrived' => round((float) $lines->sum('arrivedBase'), 6),
            'returned' => round((float) $lines->sum('returnedBase'), 6),
            'invoiced' => round((float) $lines->sum('invoicedBase'), 6),
            'remaining' => round((float) $lines->sum('remainingToPlanBase'), 6),
        ];
    }

    private function baseQuantity(InventoryOperationLine $line): float
    {
        return (float) ($line->base_quantity ?? $line->quantity ?? 0);
    }
}
