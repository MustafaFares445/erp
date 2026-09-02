<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\AllocationSource;
use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\ShipmentStatus;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\DeliveryDocumentSynchronizer;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Shipments\ShipmentAttachmentSynchronizer;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type Demand array<int, float>
 * @phpstan-type Assignment array{product_variant_id: int, quantity: float}
 * @phpstan-type ShipmentInput array{warehouse_id?: int|numeric-string|null, assignments?: array<array-key, mixed>, tracking_number?: string|null, attachments?: array<array-key, mixed>, documents?: array<string, string>, delivery_type?: string|null}
 * @phpstan-type Route array{warehouse_name: string, warehouse_address: string|null, warehouse_latitude: float|null, warehouse_longitude: float|null, distance_km: float|null, estimated_minutes: int|null, products: list<array{name: string, quantity: float}>, map_x: float|null, map_y: float|null, color: string}
 */
final readonly class OrderFulfillmentService
{
    private const float QuantityTolerance = 0.0001;

    private const float EstimatedDeliverySpeedKph = 35.0;

    /** @var list<string> */
    private const array RouteColors = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2'];

    public function __construct(
        private DeliveryWarehouseAllocationService $deliveryWarehouseAllocationService,
        private InventoryOperationService $inventoryOperationService,
        private QuantityNormalizer $quantityNormalizer,
        private DeliveryDocumentSynchronizer $deliveryDocumentSynchronizer,
        private WarehouseStockService $warehouseStockService,
        private ShipmentAttachmentSynchronizer $shipmentAttachmentSynchronizer,
    ) {}

    /**
     * @param  array<array-key, mixed>  $products
     * @return list<ShipmentInput>
     */
    public function suggest(CustomerProfile $customer, array $products): array
    {
        $latitude = $this->coordinate($customer->latitude);
        $longitude = $this->coordinate($customer->longitude);

        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages(['customer_id' => 'The selected customer needs delivery coordinates before allocating warehouses.']);
        }

        return $this->deliveryWarehouseAllocationService->allocate($latitude, $longitude, $products);
    }

    /**
     * @return array{available_quantity: float, warehouses: list<array{id: int, name: string, available_quantity: float}>}
     */
    public function availability(int $productVariantId): array
    {
        return $this->warehouseStockService->availability($productVariantId);
    }

    /**
     * @param  array<array-key, mixed>  $products
     * @param  array<array-key, mixed>  $shipments
     */
    public function validateFulfillment(array $products, array $shipments): void
    {
        $this->deliveryWarehouseAllocationService->validate($products, $shipments);
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @return list<Route>
     */
    public function routePreviews(CustomerProfile $customer, array $shipments): array
    {
        $warehouseIds = collect($shipments)
            ->map(fn (mixed $shipment): ?int => is_array($shipment) ? $this->integer($shipment['warehouse_id'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'name', 'address', 'latitude', 'longitude'])
            ->keyBy('id');
        $variantIds = $this->assignmentVariantIds($shipments);
        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('id', $variantIds)
            ->get(['id', 'product_id', 'name', 'sku'])
            ->keyBy('id');
        $routes = [];

        $routeIndex = 0;

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            if (($warehouseId = $this->integer($shipment['warehouse_id'] ?? null)) === null) {
                continue;
            }

            $warehouse = $warehouses->get($warehouseId);

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $products = [];

            foreach ($this->previewAssignments($shipment) as $assignment) {
                $variant = $variants->get($assignment['product_variant_id']);

                if (! $variant instanceof ProductVariant) {
                    continue;
                }

                $product = $variant->product;
                $products[] = [
                    'name' => $product instanceof Product ? $product->name : ($variant->name ?? $variant->sku),
                    'quantity' => $assignment['quantity'],
                ];
            }

            $distance = $this->distance($customer->latitude, $customer->longitude, $warehouse->latitude, $warehouse->longitude);
            $routes[] = [
                'warehouse_name' => $warehouse->name,
                'warehouse_address' => $warehouse->address,
                'warehouse_latitude' => $this->coordinate($warehouse->latitude),
                'warehouse_longitude' => $this->coordinate($warehouse->longitude),
                'distance_km' => $distance,
                'estimated_minutes' => $distance === null ? null : (int) ceil(($distance / self::EstimatedDeliverySpeedKph) * 60),
                'products' => $products,
                'map_x' => null,
                'map_y' => null,
                'color' => self::RouteColors[$routeIndex % count(self::RouteColors)],
            ];
            $routeIndex++;
        }

        return $this->positionRoutes($routes, $customer);
    }

    public function create(OrderFulfillmentData $fulfillment): Order
    {
        return DB::transaction(function () use ($fulfillment): Order {
            $demands = $this->demands($fulfillment->products);
            $assignments = $this->assignments($fulfillment->shipments, $demands);
            $this->assertAssignmentsMatchDemand($demands, $assignments);
            $this->lockWarehouses(array_keys($assignments));
            $this->assertStocksCanFulfill($assignments, true);

            $variants = $this->requestedVariants($demands);
            $order = $this->newOrder($fulfillment);
            $this->createOrderLines($order, $demands, $variants);
            $this->createDeliveries(
                $order,
                $assignments,
                $variants,
                $fulfillment,
                $this->serialAssignments($fulfillment->shipments),
                $this->lotAssignments($fulfillment->shipments),
            );

            return $order->refresh();
        }, attempts: 5);
    }

    /**
     * Fulfil an already-existing sales order instead of creating a second
     * commercial document. This is the canonical path used by quotation
     * conversion and by any future customer-order channel.
     */
    public function prepareExisting(Order $order, OrderFulfillmentData $fulfillment): Order
    {
        return DB::transaction(function () use ($order, $fulfillment): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->with(['lines', 'customer'])
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->sole();

            if ((int) $locked->customer_id !== (int) $fulfillment->customer->getKey()) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Fulfillment must use the sales order customer.',
                ]);
            }

            if ($locked->deliveries()->where('stage', '!=', 'canceled')->exists()
                || $locked->shipments()->exists()) {
                throw ValidationException::withMessages([
                    'shipments' => 'This sales order already has fulfillment records.',
                ]);
            }

            if (in_array($locked->status, ['pending_supplier_confirmation', 'supplier_rejected'], true)) {
                throw ValidationException::withMessages([
                    'shipments' => 'This sales order is blocked by supplier confirmation.',
                ]);
            }

            $products = $this->productsForOrder($locked);
            $demands = $this->demands($products);
            $assignments = $this->assignments($fulfillment->shipments, $demands);
            $this->assertAssignmentsMatchDemand($demands, $assignments);
            $this->lockWarehouses(array_keys($assignments));
            $this->assertStocksCanFulfill($assignments, true);

            $variants = $this->requestedVariants($demands);

            $locked->forceFill([
                'customer_delivery_address_id' => $fulfillment->deliveryAddress?->getKey(),
                'scheduled_at' => $fulfillment->scheduledAt,
                'delivery_type' => $this->aggregateDeliveryType($fulfillment->shipments),
                'responsible_id' => $fulfillment->responsible?->getKey(),
                'destination_address_snapshot' => $this->destinationAddressSnapshot($fulfillment),
                'notes' => $fulfillment->notes ?? $locked->notes,
                'status' => 'ready',
                'updated_by' => $fulfillment->actor->getKey(),
            ])->save();

            $this->createDeliveries(
                $locked,
                $assignments,
                $variants,
                $fulfillment,
                $this->serialAssignments($fulfillment->shipments),
                $this->lotAssignments($fulfillment->shipments),
            );

            activity()
                ->performedOn($locked)
                ->causedBy($fulfillment->actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.order.fulfillment_prepared');

            return $locked->refresh()->load(['deliveries', 'shipments']);
        }, attempts: 5);
    }

    /**
     * Commercial order lines may use different transaction UOMs. Fulfillment
     * planning works in base stock quantity, so duplicate variant/UOM lines are
     * aggregated here without losing their individual provenance.
     *
     * @return list<array{product_variant_id:int,quantity:float}>
     */
    public function productsForOrder(Order $order): array
    {
        $order->loadMissing('lines');
        $products = [];

        foreach ($order->lines as $line) {
            $variantId = (int) $line->product_variant_id;
            $baseQuantity = (float) ($line->base_quantity
                ?? ((float) $line->quantity * (float) ($line->conversion_factor_snapshot ?? 1)));

            $products[$variantId] = ($products[$variantId] ?? 0.0) + $baseQuantity;
        }

        return collect($products)
            ->map(fn (float $quantity, int $variantId): array => [
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Demand  $demands
     * @return Collection<int, ProductVariant>
     */
    private function requestedVariants(array $demands): Collection
    {
        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($demands))
            ->lockForUpdate()
            ->get(['id', 'unit_id', 'track_serials', 'track_batches'])
            ->keyBy('id');

        if ($variants->count() !== count($demands)) {
            throw ValidationException::withMessages(['products' => 'One or more selected products are no longer available.']);
        }

        return $variants;
    }

    private function newOrder(OrderFulfillmentData $fulfillment): Order
    {
        return Order::query()->create([
            'order_number' => $this->nextOrderNumber(),
            'customer_id' => $fulfillment->customer->getKey(),
            'customer_delivery_address_id' => $fulfillment->deliveryAddress?->getKey(),
            'status' => 'ready',
            'scheduled_at' => $fulfillment->scheduledAt,
            'delivery_type' => $this->aggregateDeliveryType($fulfillment->shipments),
            'responsible_id' => $fulfillment->responsible?->getKey(),
            'destination_address_snapshot' => $this->destinationAddressSnapshot($fulfillment),
            'notes' => $fulfillment->notes,
            'created_by' => $fulfillment->actor->getKey(),
            'updated_by' => $fulfillment->actor->getKey(),
        ]);
    }

    /**
     * @param  Demand  $demands
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function createOrderLines(Order $order, array $demands, Collection $variants): void
    {
        foreach ($demands as $variantId => $quantity) {
            $variant = $this->variant($variants, $variantId);

            if (! is_int($variant->unit_id)) {
                throw new DomainException('An order variant requires an integer base unit identifier.');
            }

            $snapshot = $this->quantityNormalizer->normalize(
                $variant,
                $variant->unit_id,
                $this->quantityString($quantity),
            );

            $order->lines()->create([
                'product_variant_id' => $variantId,
                'quantity' => $snapshot->transactionQuantity,
                'unit_id' => $snapshot->transactionUnitId,
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
            ]);
        }
    }

    /**
     * @param  array<int, array<int, float>>  $assignments
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, array<int, list<int>>>  $serialAssignments
     * @param  array<int, array<int, list<array{inventory_lot_id: int, quantity: float}>>>  $lotAssignments
     */
    private function createDeliveries(Order $order, array $assignments, Collection $variants, OrderFulfillmentData $fulfillment, array $serialAssignments, array $lotAssignments): void
    {
        $warehouseIds = array_keys($assignments);
        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'name', 'address', 'latitude', 'longitude'])
            ->keyBy('id');

        $commercialLinesByVariant = [];
        $remainingCommercialBase = [];

        foreach ($order->lines()->orderBy('id')->get() as $orderLine) {
            $variantId = (int) $orderLine->product_variant_id;
            $lineId = (int) $orderLine->getKey();
            $commercialLinesByVariant[$variantId][] = $lineId;
            $remainingCommercialBase[$lineId] = (float) ($orderLine->base_quantity
                ?? ((float) $orderLine->quantity * (float) ($orderLine->conversion_factor_snapshot ?? 1)));
        }

        foreach ($assignments as $warehouseId => $warehouseAssignments) {
            $warehouse = $warehouses->get($warehouseId);

            if (! $warehouse instanceof Warehouse) {
                throw new DomainException('The assigned warehouse could not be loaded.');
            }

            $shipmentInput = $this->shipmentInput($fulfillment->shipments, $warehouseId);

            $delivery = InventoryOperation::query()->create([
                'operation_type' => OperationType::Delivery,
                'source_warehouse_id' => $warehouseId,
                'customer_id' => $fulfillment->customer->getKey(),
                'customer_delivery_address_id' => $fulfillment->deliveryAddress?->getKey(),
                'source_document_type' => Order::class,
                'source_document_id' => $order->getKey(),
                'scheduled_at' => $fulfillment->scheduledAt,
                'delivery_type' => $this->shipmentDeliveryType($shipmentInput),
                'responsible_id' => $fulfillment->responsible?->getKey(),
                'source_address_snapshot' => $this->warehouseAddressSnapshot($warehouse),
                'destination_address_snapshot' => $this->destinationAddressSnapshot($fulfillment),
                'notes' => $fulfillment->notes,
                'created_by' => $fulfillment->actor->getKey(),
                'updated_by' => $fulfillment->actor->getKey(),
            ]);

            foreach ($warehouseAssignments as $variantId => $quantity) {
                $variant = $this->variant($variants, $variantId);
                $allocationSource = $this->allocationSource($fulfillment, $warehouseId, $variantId);
                $serialIds = array_values(array_unique($serialAssignments[$warehouseId][$variantId] ?? []));
                $lotRows = $lotAssignments[$warehouseId][$variantId] ?? [];

                if ($variant->track_serials) {
                    if (count($serialIds) !== (int) $quantity) {
                        throw ValidationException::withMessages([
                            'shipments' => 'Select exactly one serial number for every unit of a serialized product.',
                        ]);
                    }

                    $units = SerializedInventoryUnit::query()
                        ->whereIn('id', $serialIds)
                        ->where('product_variant_id', $variantId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('status', SerializedInventoryUnitStatus::Available->value)
                        ->lockForUpdate()
                        ->get();

                    if ($units->count() !== count($serialIds)) {
                        throw ValidationException::withMessages([
                            'shipments' => 'One or more selected serial numbers are no longer available in that warehouse.',
                        ]);
                    }

                    foreach ($serialIds as $serialId) {
                        $splits = $this->consumeCommercialBase(
                            $commercialLinesByVariant,
                            $remainingCommercialBase,
                            $variantId,
                            1.0,
                        );

                        if (count($splits) !== 1) {
                            throw new DomainException('A serialized inventory unit must map to exactly one sales order line.');
                        }

                        $delivery->lines()->create([
                            'product_variant_id' => $variantId,
                            'order_line_id' => $splits[0]['order_line_id'],
                            'quantity' => 1,
                            'unit_id' => $variant->unit_id,
                            'serialized_inventory_unit_id' => $serialId,
                            'allocation_source' => $allocationSource,
                        ]);
                    }

                    continue;
                }

                if ($serialIds !== []) {
                    throw ValidationException::withMessages([
                        'shipments' => 'Serial numbers may only be selected for serialized products.',
                    ]);
                }

                if ($variant->track_batches) {
                    $lotQuantity = array_sum(array_column($lotRows, 'quantity'));

                    if ($lotRows === [] || abs($lotQuantity - $quantity) > self::QuantityTolerance) {
                        throw ValidationException::withMessages([
                            'shipments' => 'Select a batch for every unit of a batch-tracked product.',
                        ]);
                    }

                    foreach ($lotRows as $lotRow) {
                        foreach ($this->consumeCommercialBase(
                            $commercialLinesByVariant,
                            $remainingCommercialBase,
                            $variantId,
                            (float) $lotRow['quantity'],
                        ) as $split) {
                            $delivery->lines()->create([
                                'product_variant_id' => $variantId,
                                'order_line_id' => $split['order_line_id'],
                                'quantity' => $split['base_quantity'],
                                'unit_id' => $variant->unit_id,
                                'inventory_lot_id' => $lotRow['inventory_lot_id'],
                                'allocation_source' => $allocationSource,
                            ]);
                        }
                    }

                    continue;
                }

                if ($lotRows !== []) {
                    throw ValidationException::withMessages([
                        'shipments' => 'Batches may only be selected for batch-tracked products.',
                    ]);
                }

                foreach ($this->consumeCommercialBase(
                    $commercialLinesByVariant,
                    $remainingCommercialBase,
                    $variantId,
                    $quantity,
                ) as $split) {
                    $delivery->lines()->create([
                        'product_variant_id' => $variantId,
                        'order_line_id' => $split['order_line_id'],
                        'quantity' => $split['base_quantity'],
                        'unit_id' => $variant->unit_id,
                        'allocation_source' => $allocationSource,
                    ]);
                }
            }

            $this->inventoryOperationService->markReady($delivery, $fulfillment->actor);

            foreach ($fulfillment->documents as $collection => $path) {
                $this->deliveryDocumentSynchronizer->sync($delivery, $collection, $path);
            }

            $shipment = $order->shipments()->create([
                'inventory_operation_id' => $delivery->getKey(),
                'warehouse_id' => $warehouseId,
                'tracking_number' => $this->trackingNumber($shipmentInput),
                'status' => ShipmentStatus::InTransit,
            ]);

            $this->shipmentAttachmentSynchronizer->sync($shipment, $this->attachments($shipmentInput));
        }
    }

    /**
     * Consume a physical base quantity from the order's commercial lines in
     * stable document order. This is what preserves exact OrderLine provenance
     * when one SKU was sold in more than one transaction UOM.
     *
     * @param array<int, list<int>> $commercialLinesByVariant
     * @param array<int, float> $remainingCommercialBase
     * @return list<array{order_line_id:int,base_quantity:float}>
     */
    private function consumeCommercialBase(
        array $commercialLinesByVariant,
        array &$remainingCommercialBase,
        int $variantId,
        float $requestedBase,
    ): array {
        $remainingRequest = $requestedBase;
        $splits = [];

        foreach ($commercialLinesByVariant[$variantId] ?? [] as $lineId) {
            $available = $remainingCommercialBase[$lineId] ?? 0.0;

            if ($available <= self::QuantityTolerance) {
                continue;
            }

            $taken = min($available, $remainingRequest);

            if ($taken > self::QuantityTolerance) {
                $splits[] = [
                    'order_line_id' => $lineId,
                    'base_quantity' => $taken,
                ];
                $remainingCommercialBase[$lineId] = $available - $taken;
                $remainingRequest -= $taken;
            }

            if ($remainingRequest <= self::QuantityTolerance) {
                break;
            }
        }

        if ($remainingRequest > self::QuantityTolerance) {
            throw new DomainException('Fulfillment quantity exceeds the remaining commercial sales order quantity.');
        }

        return $splits;
    }

    /** @param Collection<int, ProductVariant> $variants */
    private function variant(Collection $variants, int $variantId): ProductVariant
    {
        $variant = $variants->get($variantId);

        if (! $variant instanceof ProductVariant) {
            throw new DomainException('The assigned product variant could not be loaded.');
        }

        return $variant;
    }

    /** @param list<int> $warehouseIds */
    private function lockWarehouses(array $warehouseIds): void
    {
        $count = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->count();

        if ($count !== count($warehouseIds)) {
            throw ValidationException::withMessages(['shipments' => 'One or more selected warehouses are no longer available.']);
        }
    }

    /** @return array{address: string, country: string|null, city: string|null, latitude: float|null, longitude: float|null, contact_name: string|null, contact_phone: string|null} */
    private function destinationAddressSnapshot(OrderFulfillmentData $fulfillment): array
    {
        $address = $fulfillment->deliveryAddress;

        if ($address instanceof CustomerDeliveryAddress) {
            return [
                'address' => $address->address,
                'country' => $address->country,
                'city' => $address->city,
                'latitude' => $this->coordinate($address->latitude),
                'longitude' => $this->coordinate($address->longitude),
                'contact_name' => $address->contact_name,
                'contact_phone' => $address->contact_phone,
            ];
        }

        return [
            'address' => $fulfillment->customer->address ?? '',
            'country' => $fulfillment->customer->country,
            'city' => $fulfillment->customer->city,
            'latitude' => $this->coordinate($fulfillment->customer->latitude),
            'longitude' => $this->coordinate($fulfillment->customer->longitude),
            'contact_name' => $fulfillment->customer->contact_name,
            'contact_phone' => $fulfillment->customer->contact_phone,
        ];
    }

    /** @return array{address: string|null, latitude: float|null, longitude: float|null, name: string} */
    private function warehouseAddressSnapshot(Warehouse $warehouse): array
    {
        return [
            'address' => $warehouse->address,
            'latitude' => $this->coordinate($warehouse->latitude),
            'longitude' => $this->coordinate($warehouse->longitude),
            'name' => $warehouse->name,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @return ShipmentInput
     */
    private function shipmentInput(array $shipments, int $warehouseId): array
    {
        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            if ($this->integer($shipment['warehouse_id'] ?? null) !== $warehouseId) {
                continue;
            }

            $assignments = $shipment['assignments'] ?? [];
            $attachments = $shipment['attachments'] ?? [];

            return [
                'warehouse_id' => $warehouseId,
                'assignments' => is_array($assignments) ? $assignments : [],
                'tracking_number' => is_string($shipment['tracking_number'] ?? null) ? $shipment['tracking_number'] : null,
                'attachments' => is_array($attachments) ? $attachments : [],
                'delivery_type' => is_string($shipment['delivery_type'] ?? null) ? $shipment['delivery_type'] : null,
            ];
        }

        return [];
    }

    /** @param array<array-key, mixed> $shipment */
    private function shipmentDeliveryType(array $shipment): DeliveryType
    {
        $value = $shipment['delivery_type'] ?? null;

        return is_string($value) ? (DeliveryType::tryFrom($value) ?? DeliveryType::Inner) : DeliveryType::Inner;
    }

    /**
     * The order carries {@see DeliveryType::Outer} whenever any of its shipments does, since that
     * is what determines whether the order as a whole needs cross-border handling.
     *
     * @param  array<array-key, mixed>  $shipments
     */
    private function aggregateDeliveryType(array $shipments): DeliveryType
    {
        foreach ($shipments as $shipment) {
            if (is_array($shipment) && $this->shipmentDeliveryType($shipment) === DeliveryType::Outer) {
                return DeliveryType::Outer;
            }
        }

        return DeliveryType::Inner;
    }

    /** @param ShipmentInput $shipment */
    private function trackingNumber(array $shipment): ?string
    {
        $trackingNumber = $shipment['tracking_number'] ?? null;

        if (! is_string($trackingNumber) || blank($trackingNumber)) {
            return null;
        }

        return mb_trim($trackingNumber);
    }

    /**
     * @param  ShipmentInput  $shipment
     * @return list<string>
     */
    private function attachments(array $shipment): array
    {
        $attachments = $shipment['attachments'] ?? [];

        return array_values(array_filter($attachments, is_string(...)));
    }

    private function allocationSource(OrderFulfillmentData $fulfillment, int $warehouseId, int $variantId): AllocationSource
    {
        foreach ($fulfillment->shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            if ($this->integer($shipment['warehouse_id'] ?? null) !== $warehouseId) {
                continue;
            }

            $shipmentAssignments = $shipment['assignments'] ?? [];

            if (! is_array($shipmentAssignments)) {
                continue;
            }

            foreach ($shipmentAssignments as $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                if ($this->integer($assignment['product_variant_id'] ?? null) !== $variantId) {
                    continue;
                }

                return ($assignment['allocation_source'] ?? null) === AllocationSource::Manual->value
                    ? AllocationSource::Manual
                    : AllocationSource::Automatic;
            }
        }

        return AllocationSource::Automatic;
    }

    /**
     * @param  array<array-key, mixed>  $products
     * @return Demand
     */
    private function demands(array $products): array
    {
        $demands = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $variantId = $this->integer($product['product_variant_id'] ?? null);
            $quantity = $this->positiveFloat($product['quantity'] ?? null);

            if ($variantId === null || $quantity === null) {
                throw ValidationException::withMessages(['products' => 'Each selected product needs a valid quantity.']);
            }

            $demands[$variantId] = ($demands[$variantId] ?? 0.0) + $quantity;
        }

        if ($demands === []) {
            throw ValidationException::withMessages(['products' => 'Select at least one product before continuing.']);
        }

        return $demands;
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @param  Demand  $demands
     * @return array<int, array<int, float>>
     */
    private function assignments(array $shipments, array $demands): array
    {
        $assignments = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            $warehouseId = $this->integer($shipment['warehouse_id'] ?? null);

            if ($warehouseId === null) {
                throw ValidationException::withMessages(['shipments' => 'Select a warehouse for every shipment.']);
            }

            if (array_key_exists($warehouseId, $assignments)) {
                throw ValidationException::withMessages(['shipments' => 'Each selected warehouse may only have one shipment.']);
            }

            foreach ($this->shipmentAssignments($shipment) as $assignment) {
                $variantId = $assignment['product_variant_id'];

                if (! array_key_exists($variantId, $demands)) {
                    throw ValidationException::withMessages(['shipments' => 'A shipment contains a product that was not selected.']);
                }

                $assignments[$warehouseId][$variantId] = ($assignments[$warehouseId][$variantId] ?? 0.0) + $assignment['quantity'];
            }
        }

        if ($assignments === []) {
            throw ValidationException::withMessages(['shipments' => 'Assign every selected product to a warehouse.']);
        }

        return $assignments;
    }

    /**
     * The serial numbers named in each shipment's assignments, keyed the same way as
     * {@see self::assignments()} so `createDeliveries()` can look either up by warehouse and
     * variant. Rows for the same variant within one shipment are merged, mirroring how their
     * quantities are summed.
     *
     * @param  array<array-key, mixed>  $shipments
     * @return array<int, array<int, list<int>>>
     */
    private function serialAssignments(array $shipments): array
    {
        $serials = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            $warehouseId = $this->integer($shipment['warehouse_id'] ?? null);
            $shipmentAssignments = $shipment['assignments'] ?? null;
            if ($warehouseId === null) {
                continue;
            }

            if (! is_array($shipmentAssignments)) {
                continue;
            }

            foreach ($shipmentAssignments as $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $variantId = $this->integer($assignment['product_variant_id'] ?? null);
                $serialIds = $this->integers($assignment['serialized_inventory_unit_ids'] ?? null);
                if ($variantId === null) {
                    continue;
                }

                if ($serialIds === []) {
                    continue;
                }

                $serials[$warehouseId][$variantId] = array_merge($serials[$warehouseId][$variantId] ?? [], $serialIds);
            }
        }

        return $serials;
    }

    /**
     * The batch each shipment's assignments draw from, keyed the same way as
     * {@see self::assignments()} so `createDeliveries()` can look them up by warehouse and
     * variant. Unlike a serial, one row names one lot for its own quantity — a batch-tracked
     * product split across two batches needs two assignment rows, each producing its own
     * delivery line, so every unit stays traceable to the specific batch it left in.
     *
     * @param  array<array-key, mixed>  $shipments
     * @return array<int, array<int, list<array{inventory_lot_id: int, quantity: float}>>>
     */
    private function lotAssignments(array $shipments): array
    {
        $lots = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            $warehouseId = $this->integer($shipment['warehouse_id'] ?? null);
            $shipmentAssignments = $shipment['assignments'] ?? null;
            if ($warehouseId === null) {
                continue;
            }

            if (! is_array($shipmentAssignments)) {
                continue;
            }

            foreach ($shipmentAssignments as $assignment) {
                if (! is_array($assignment)) {
                    continue;
                }

                $variantId = $this->integer($assignment['product_variant_id'] ?? null);
                $lotId = $this->integer($assignment['inventory_lot_id'] ?? null);
                $quantity = $this->positiveFloat($assignment['quantity'] ?? null);
                if ($variantId === null) {
                    continue;
                }

                if ($lotId === null) {
                    continue;
                }

                if ($quantity === null) {
                    continue;
                }

                $lots[$warehouseId][$variantId][] = ['inventory_lot_id' => $lotId, 'quantity' => $quantity];
            }
        }

        return $lots;
    }

    /**
     * Route details are also shown while a warehouse row is being assembled,
     * before that row has any product assignments. Validation still uses
     * {@see shipmentAssignments()} and rejects empty shipments before saving.
     *
     * @param  array<array-key, mixed>  $shipment
     * @return list<Assignment>
     */
    private function previewAssignments(array $shipment): array
    {
        $shipmentAssignments = $shipment['assignments'] ?? [];

        if (! is_array($shipmentAssignments)) {
            return [];
        }

        $assignments = [];

        foreach ($shipmentAssignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $variantId = $this->integer($assignment['product_variant_id'] ?? null);
            $quantity = $this->positiveFloat($assignment['quantity'] ?? null);
            if ($variantId === null) {
                continue;
            }

            if ($quantity === null) {
                continue;
            }

            $assignments[] = ['product_variant_id' => $variantId, 'quantity' => $quantity];
        }

        return $assignments;
    }

    /**
     * @param  array<array-key, mixed>  $shipment
     * @return list<Assignment>
     */
    private function shipmentAssignments(array $shipment): array
    {
        $shipmentAssignments = $shipment['assignments'] ?? null;

        if (! is_array($shipmentAssignments)) {
            throw ValidationException::withMessages(['shipments' => 'Remove warehouses that have no assigned products.']);
        }

        $assignments = $this->previewAssignments($shipment);

        if (count($assignments) !== count($shipmentAssignments)) {
            throw ValidationException::withMessages(['shipments' => 'Each warehouse assignment needs a product and quantity.']);
        }

        if ($assignments === []) {
            throw ValidationException::withMessages(['shipments' => 'Remove warehouses that have no assigned products.']);
        }

        return $assignments;
    }

    /**
     * @param  Demand  $demands
     * @param  array<int, array<int, float>>  $assignments
     */
    private function assertAssignmentsMatchDemand(array $demands, array $assignments): void
    {
        foreach ($demands as $variantId => $demandedQuantity) {
            $assignedQuantity = 0.0;

            foreach ($assignments as $warehouseAssignments) {
                $assignedQuantity += $warehouseAssignments[$variantId] ?? 0.0;
            }

            if (abs($assignedQuantity - $demandedQuantity) > self::QuantityTolerance) {
                throw ValidationException::withMessages(['shipments' => 'Every selected product must be fully assigned, without exceeding its requested quantity.']);
            }
        }
    }

    /** @param array<int, array<int, float>> $assignments */
    private function assertStocksCanFulfill(array $assignments, bool $lock): void
    {
        $warehouseIds = array_keys($assignments);
        $variantIds = array_values(array_unique(array_merge(...array_map(array_keys(...), $assignments))));
        $warehouseQuery = Warehouse::query()->whereIn('id', $warehouseIds)->where('is_active', true);

        if ($lock) {
            $warehouseQuery->lockForUpdate();
        }

        if ($warehouseQuery->count() !== count($warehouseIds)) {
            throw ValidationException::withMessages(['shipments' => 'One or more selected warehouses are no longer available.']);
        }

        $stockQuery = InventoryStock::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('product_variant_id', $variantIds);

        if ($lock) {
            $stockQuery->lockForUpdate();
        }

        $stocks = $stockQuery->get()->keyBy(fn (InventoryStock $stock): string => sprintf('%s:%s', $stock->warehouse_id, $stock->product_variant_id));

        foreach ($assignments as $warehouseId => $warehouseAssignments) {
            foreach ($warehouseAssignments as $variantId => $assignedQuantity) {
                $stock = $stocks->get(sprintf('%d:%d', $warehouseId, $variantId));

                if (! $stock instanceof InventoryStock || (float) $stock->available_quantity + self::QuantityTolerance < $assignedQuantity) {
                    throw ValidationException::withMessages(['shipments' => 'The assigned quantity exceeds the current available stock.']);
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @return list<int>
     */
    private function assignmentVariantIds(array $shipments): array
    {
        $variantIds = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            foreach ($this->previewAssignments($shipment) as $assignment) {
                $variantIds[] = $assignment['product_variant_id'];
            }
        }

        return array_values(array_unique($variantIds));
    }

    /**
     * @param  list<Route>  $routes
     * @return list<Route>
     */
    private function positionRoutes(array $routes, CustomerProfile $customer): array
    {
        $customerLatitude = $this->coordinate($customer->latitude);
        $customerLongitude = $this->coordinate($customer->longitude);

        if ($customerLatitude === null || $customerLongitude === null) {
            return $routes;
        }

        $maxDelta = 0.0;

        foreach ($routes as $route) {
            if ($route['warehouse_latitude'] === null) {
                continue;
            }

            if ($route['warehouse_longitude'] === null) {
                continue;
            }

            $maxDelta = max($maxDelta, abs($route['warehouse_latitude'] - $customerLatitude), abs($route['warehouse_longitude'] - $customerLongitude));
        }

        if ($maxDelta <= 0.0) {
            return $routes;
        }

        foreach ($routes as $index => $route) {
            if ($route['warehouse_latitude'] === null) {
                continue;
            }

            if ($route['warehouse_longitude'] === null) {
                continue;
            }

            $routes[$index]['map_x'] = 50 + (($route['warehouse_longitude'] - $customerLongitude) / $maxDelta) * 38;
            $routes[$index]['map_y'] = 50 - (($route['warehouse_latitude'] - $customerLatitude) / $maxDelta) * 38;
        }

        return $routes;
    }

    private function distance(mixed $fromLatitude, mixed $fromLongitude, mixed $toLatitude, mixed $toLongitude): ?float
    {
        $fromLatitude = $this->coordinate($fromLatitude);
        $fromLongitude = $this->coordinate($fromLongitude);
        $toLatitude = $this->coordinate($toLatitude);
        $toLongitude = $this->coordinate($toLongitude);

        if ($fromLatitude === null || $fromLongitude === null || $toLatitude === null || $toLongitude === null) {
            return null;
        }

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $value = sin($latitudeDelta / 2) ** 2 + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }

    private function nextOrderNumber(): string
    {
        $highestNumber = Order::query()->whereNotNull('order_number')->lockForUpdate()->max('order_number');
        $next = is_string($highestNumber) ? (int) mb_substr($highestNumber, 3) + 1 : 1;

        return sprintf('SO-%06d', $next);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function quantityString(float $quantity): string
    {
        return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }

        return (float) $value;
    }

    /** @return list<int> */
    private function integers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $integers = [];

        foreach ($value as $item) {
            $integer = $this->integer($item);

            if ($integer !== null) {
                $integers[] = $integer;
            }
        }

        return $integers;
    }

    private function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
