<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Enums\AllocationSource;
use App\Enums\DeliveryType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Sales\OrderFulfillmentQuantityService;
use App\Services\Shipments\ShipmentAttachmentSynchronizer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type AssignmentInput array{
 *     product_variant_id: int,
 *     quantity: float,
 *     inventory_lot_id: int|null,
 *     serialized_inventory_unit_ids: list<int>
 * }
 * @phpstan-type ShipmentInput array{
 *     warehouse_id: int,
 *     tracking_number: string|null,
 *     attachments: list<string>,
 *     delivery_type: string|null,
 *     assignments: list<AssignmentInput>
 * }
 */
final readonly class OutboundFulfillmentService
{
    private const float Tolerance = 0.000001;

    public function __construct(
        private OrderFulfillmentQuantityService $quantities,
        private OrderFulfillmentService $allocationSuggestions,
        private InventoryOperationService $inventoryOperations,
        private ShipmentAttachmentSynchronizer $shipmentAttachments,
    ) {}

    /** @return list<array<string, mixed>> */
    public function suggest(Order $order): array
    {
        $order->loadMissing(['customer', 'lines']);
        if (! $order->customer) {
            throw new DomainException('The sales order customer is unavailable.');
        }

        $progress = $this->quantities->forOrder($order);
        $byVariant = [];
        foreach ($progress as $line) {
            if ($line->remainingToPlanBase <= self::Tolerance) {
                continue;
            }
            $byVariant[$line->productVariantId] = ($byVariant[$line->productVariantId] ?? 0.0) + $line->remainingToPlanBase;
        }

        $products = [];
        foreach ($byVariant as $variantId => $quantity) {
            $products[] = ['product_variant_id' => $variantId, 'quantity' => $quantity];
        }

        return $products === [] ? [] : $this->allocationSuggestions->suggest($order->customer, $products);
    }

    /**
     * Create Draft Delivery operations and Planned Shipments for any subset of
     * remaining released demand. This method deliberately does not reserve stock.
     *
     * @param  list<array<string, mixed>>  $shipments
     */
    public function plan(User $actor, Order $order, array $shipments): Order
    {
        Gate::forUser($actor)->authorize('planFulfillment', $order);

        return DB::transaction(function () use ($actor, $order, $shipments): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->sole();
            if ($locked->status !== OrderStatus::Released) {
                throw new DomainException('Only a released customer order can be planned by Logistics.');
            }

            $commercialLines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            if ($commercialLines->isEmpty()) {
                throw new DomainException('The released order has no commercial demand.');
            }

            $deliveryIds = $locked->deliveries()
                ->where('stage', '!=', OperationStage::Canceled->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('inventory_operations.id')
                ->all();

            $existingLines = InventoryOperationLine::query()
                ->whereIn('inventory_operation_id', $deliveryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingByLine = [];
            $lineIdsByVariant = [];
            foreach ($commercialLines as $line) {
                $planned = (float) $existingLines->where('order_line_id', $line->id)->sum(
                    fn (InventoryOperationLine $deliveryLine): float => (float) ($deliveryLine->base_quantity ?? $deliveryLine->quantity),
                );
                $remainingByLine[$line->id] = max(
                    0.0,
                    (float) ($line->base_quantity ?? 0) - (float) $line->short_closed_base_quantity - $planned,
                );
                $lineIdsByVariant[(int) $line->product_variant_id][] = $line->id;
            }

            $normalizedShipments = $this->normalizeShipments($shipments);
            $requestedByVariant = [];
            foreach ($normalizedShipments as $shipment) {
                foreach ($shipment['assignments'] as $assignment) {
                    $variantId = $assignment['product_variant_id'];
                    $requestedByVariant[$variantId] = ($requestedByVariant[$variantId] ?? 0.0) + $assignment['quantity'];
                }
            }

            foreach ($requestedByVariant as $variantId => $requested) {
                $remaining = array_sum(array_map(
                    fn (int $lineId): float => $remainingByLine[$lineId] ?? 0.0,
                    $lineIdsByVariant[$variantId] ?? [],
                ));
                if ($requested > $remaining + self::Tolerance) {
                    throw ValidationException::withMessages([
                        'shipments' => 'Planned quantity exceeds the remaining released customer demand.',
                    ]);
                }
            }

            $this->lockAndValidateStock($normalizedShipments);
            $variantIds = array_keys($requestedByVariant);
            $variants = ProductVariant::query()->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');
            if ($variants->count() !== count($variantIds)) {
                throw ValidationException::withMessages(['shipments' => 'A planned product variant is unavailable.']);
            }

            $warehouseIds = array_column($normalizedShipments, 'warehouse_id');
            $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($normalizedShipments as $shipmentInput) {
                $warehouse = $warehouses->get($shipmentInput['warehouse_id']);
                if (! $warehouse instanceof Warehouse) {
                    throw new DomainException('The selected warehouse is unavailable.');
                }

                $delivery = InventoryOperation::query()->create([
                    'operation_type' => OperationType::Delivery,
                    'source_warehouse_id' => $warehouse->id,
                    'customer_id' => $locked->customer_id,
                    'customer_delivery_address_id' => $locked->customer_delivery_address_id,
                    'source_document_type' => Order::class,
                    'source_document_id' => $locked->getKey(),
                    'scheduled_at' => $locked->scheduled_at,
                    'delivery_type' => $this->deliveryType($locked, $shipmentInput),
                    'responsible_id' => $locked->responsible_id,
                    'source_address_snapshot' => [
                        'address' => $warehouse->address,
                        'latitude' => $warehouse->latitude,
                        'longitude' => $warehouse->longitude,
                        'name' => $warehouse->name,
                    ],
                    'destination_address_snapshot' => $locked->destination_address_snapshot,
                    'notes' => $locked->notes,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);

                foreach ($shipmentInput['assignments'] as $assignment) {
                    $variant = $variants->get($assignment['product_variant_id']);
                    // @codeCoverageIgnoreStart
                    // Unreachable in practice: $assignment['product_variant_id'] is always one of the
                    // keys the outer $variantIds/$variants count-match guard above already verified
                    // exist, since both are derived from the same $normalizedShipments assignments.
                    if (! $variant instanceof ProductVariant) {
                        throw new DomainException('The selected product variant is unavailable.');
                    }
                    // @codeCoverageIgnoreEnd

                    $serialIds = $assignment['serialized_inventory_unit_ids'];
                    if ($variant->track_serials) {
                        if (abs($assignment['quantity'] - round($assignment['quantity'])) > self::Tolerance
                            || count($serialIds) !== (int) round($assignment['quantity'])) {
                            throw ValidationException::withMessages(['shipments' => 'Serialized demand requires exactly one serial per unit.']);
                        }

                        foreach ($serialIds as $serialId) {
                            $split = $this->consume($lineIdsByVariant, $remainingByLine, $assignment['product_variant_id'], 1.0);
                            $delivery->lines()->create([
                                'product_variant_id' => $variant->id,
                                'order_line_id' => $split[0]['order_line_id'],
                                'quantity' => 1,
                                'base_quantity' => 1,
                                'unit_id' => $variant->unit_id,
                                'serialized_inventory_unit_id' => $serialId,
                                'allocation_source' => AllocationSource::Manual,
                            ]);
                        }

                        continue;
                    }

                    if ($variant->track_batches && $assignment['inventory_lot_id'] === null) {
                        throw ValidationException::withMessages(['shipments' => 'Batch-tracked demand requires a lot assignment.']);
                    }

                    foreach ($this->consume(
                        $lineIdsByVariant,
                        $remainingByLine,
                        $assignment['product_variant_id'],
                        $assignment['quantity'],
                    ) as $split) {
                        $delivery->lines()->create([
                            'product_variant_id' => $variant->id,
                            'order_line_id' => $split['order_line_id'],
                            'quantity' => $split['base_quantity'],
                            'base_quantity' => $split['base_quantity'],
                            'unit_id' => $variant->unit_id,
                            'inventory_lot_id' => $assignment['inventory_lot_id'],
                            'allocation_source' => AllocationSource::Manual,
                        ]);
                    }
                }

                $shipment = $locked->shipments()->create([
                    'inventory_operation_id' => $delivery->getKey(),
                    'warehouse_id' => $warehouse->id,
                    'tracking_number' => $shipmentInput['tracking_number'],
                    'status' => ShipmentStatus::Planned,
                ]);
                $this->shipmentAttachments->sync($shipment, $shipmentInput['attachments']);
            }

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'logistics', 'shipment_count' => count($normalizedShipments)])
                ->log('logistics.outbound.fulfillment_planned');

            return $locked->refresh()->load(['deliveries.lines', 'shipments']);
        }, attempts: 5);
    }

    public function prepare(User $actor, InventoryOperation $delivery): InventoryOperation
    {
        Gate::forUser($actor)->authorize('markReady', $delivery);

        $prepared = $this->inventoryOperations->markReady($delivery, $actor);
        activity()->performedOn($prepared)->causedBy($actor)
            ->withProperties(['source_channel' => 'logistics'])
            ->log('logistics.outbound.prepared');

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $shipments
     * @return list<ShipmentInput>
     */
    private function normalizeShipments(array $shipments): array
    {
        if ($shipments === []) {
            throw ValidationException::withMessages(['shipments' => 'Plan at least one warehouse shipment.']);
        }

        $normalized = [];
        $seenWarehouses = [];
        foreach ($shipments as $shipment) {
            if (! is_numeric($shipment['warehouse_id'] ?? null)) {
                throw ValidationException::withMessages(['shipments' => 'Every planned shipment requires a warehouse.']);
            }
            $warehouseId = (int) $shipment['warehouse_id'];
            if (isset($seenWarehouses[$warehouseId])) {
                throw ValidationException::withMessages(['shipments' => 'Use one planned shipment per warehouse.']);
            }
            $seenWarehouses[$warehouseId] = true;

            $assignments = [];
            $assignmentState = $shipment['assignments'] ?? null;

            if (! is_array($assignmentState)) {
                throw ValidationException::withMessages(['shipments' => 'Every planned shipment requires assignments.']);
            }

            foreach ($assignmentState as $assignment) {
                if (! is_array($assignment)
                    || ! is_numeric($assignment['product_variant_id'] ?? null)
                    || ! is_numeric($assignment['quantity'] ?? null)
                    || (float) $assignment['quantity'] <= 0) {
                    throw ValidationException::withMessages(['shipments' => 'Every warehouse assignment requires a product and positive quantity.']);
                }
                $serialIds = array_values(array_unique(array_map(intval(...), array_filter(
                    is_array($assignment['serialized_inventory_unit_ids'] ?? null)
                        ? $assignment['serialized_inventory_unit_ids'] : [],
                    is_numeric(...),
                ))));
                $assignments[] = [
                    'product_variant_id' => (int) $assignment['product_variant_id'],
                    'quantity' => round((float) $assignment['quantity'], 6),
                    'inventory_lot_id' => is_numeric($assignment['inventory_lot_id'] ?? null) ? (int) $assignment['inventory_lot_id'] : null,
                    'serialized_inventory_unit_ids' => $serialIds,
                ];
            }
            if ($assignments === []) {
                throw ValidationException::withMessages(['shipments' => 'Every planned shipment requires at least one product assignment.']);
            }

            $normalized[] = [
                'warehouse_id' => $warehouseId,
                'tracking_number' => is_string($shipment['tracking_number'] ?? null) ? mb_trim($shipment['tracking_number']) : null,
                'attachments' => array_values(array_filter(is_array($shipment['attachments'] ?? null) ? $shipment['attachments'] : [], is_string(...))),
                'delivery_type' => is_string($shipment['delivery_type'] ?? null) ? $shipment['delivery_type'] : null,
                'assignments' => $assignments,
            ];
        }

        return $normalized;
    }

    /** @param list<ShipmentInput> $shipments */
    private function lockAndValidateStock(array $shipments): void
    {
        $requirements = [];
        foreach ($shipments as $shipment) {
            foreach ($shipment['assignments'] as $assignment) {
                $key = $shipment['warehouse_id'].':'.$assignment['product_variant_id'];
                $requirements[$key] ??= [
                    'warehouse_id' => $shipment['warehouse_id'],
                    'product_variant_id' => $assignment['product_variant_id'],
                    'quantity' => 0.0,
                ];
                $requirements[$key]['quantity'] += $assignment['quantity'];
            }
        }

        ksort($requirements);
        foreach ($requirements as $requirement) {
            $rows = InventoryStock::query()
                ->where('warehouse_id', $requirement['warehouse_id'])
                ->where('product_variant_id', $requirement['product_variant_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $available = $this->floatInput($rows->sum('available_quantity'));
            if ($available + self::Tolerance < $requirement['quantity']) {
                throw ValidationException::withMessages([
                    'shipments' => 'Current available stock no longer covers the proposed warehouse allocation.',
                ]);
            }
        }
    }

    /**
     * @param  array<int, list<int>>  $lineIdsByVariant
     * @param  array<int, float>  $remainingByLine
     * @return list<array{order_line_id: int, base_quantity: float}>
     */
    private function consume(array $lineIdsByVariant, array &$remainingByLine, int $variantId, float $requested): array
    {
        $left = $requested;
        $splits = [];
        foreach ($lineIdsByVariant[$variantId] ?? [] as $lineId) {
            $available = $remainingByLine[$lineId] ?? 0.0;
            if ($available <= self::Tolerance) {
                continue;
            }
            $take = min($available, $left);
            if ($take > self::Tolerance) {
                $splits[] = ['order_line_id' => $lineId, 'base_quantity' => round($take, 6)];
                $remainingByLine[$lineId] = $available - $take;
                $left -= $take;
            }
            if ($left <= self::Tolerance) {
                break;
            }
        }

        if ($left > self::Tolerance) {
            throw new DomainException('Fulfillment planning exceeded the remaining commercial line quantity.');
        }

        return $splits;
    }

    /** @param ShipmentInput $shipmentInput */
    private function deliveryType(Order $order, array $shipmentInput): DeliveryType
    {
        $candidate = $shipmentInput['delivery_type'] ?? $order->delivery_type;

        return is_string($candidate) ? (DeliveryType::tryFrom($candidate) ?? DeliveryType::Inner) : DeliveryType::Inner;
    }

    private function floatInput(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new DomainException('An inventory quantity must be numeric.');
        }

        return (float) $value;
    }
}
