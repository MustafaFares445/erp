<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Purchasing\SupplierConfirmationRequestData;
use App\Enums\SupplierConfirmationStatus;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\SupplierConfirmationService;
use App\Services\Purchasing\SupplierSupportResolver;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SalesProcurementService
{
    public function __construct(
        private SupplierConfirmationService $confirmations,
        private PurchaseOrderService $purchaseOrders,
        private SupplierSupportResolver $supplierSupport,
    ) {}

    /** @return list<int> */
    public function eligibleSupplierIds(Order $order): array
    {
        $variantIds = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->pluck('product_variant_id')
            ->map(fn (mixed $id): int => $this->integerId($id))
            ->unique()
            ->values()
            ->all();

        return $this->supplierSupport->eligibleSupplierIds(array_values($variantIds));
    }

    /** @return Collection<int, SalesProcurementRequirement> */
    public function detectShortages(User $actor, Order $order): Collection
    {
        Gate::forUser($actor)->authorize('fulfill', $order);

        return DB::transaction(function () use ($actor, $order): Collection {
            /** @var Order $locked */
            $locked = Order::query()->with('lines')->whereKey($order->getKey())->lockForUpdate()->sole();

            $existing = $locked->procurementRequirements()
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->orderBy('id')
                ->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $variantIds = array_values($locked->lines->pluck('product_variant_id')->unique()->values()->all());
            $available = InventoryStock::query()
                ->whereIn('product_variant_id', $variantIds)
                ->selectRaw('product_variant_id, SUM(available_quantity) AS available_quantity')
                ->groupBy('product_variant_id')
                ->pluck('available_quantity', 'product_variant_id')
                ->map(fn (mixed $quantity): float => $this->decimalValue($quantity))
                ->all();

            /** @var Collection<int, SalesProcurementRequirement> $requirements */
            $requirements = new Collection;

            foreach ($locked->lines->sortBy('id') as $line) {
                $variantId = (int) $line->product_variant_id;
                $baseRequired = (float) ($line->base_quantity
                    ?? ((float) $line->quantity * (float) ($line->conversion_factor_snapshot ?? 1)));
                $availableForVariant = $available[$variantId] ?? 0.0;
                $covered = min($baseRequired, $availableForVariant);
                $available[$variantId] = max(0.0, $availableForVariant - $covered);
                $shortage = round($baseRequired - $covered, 6);

                if ($shortage <= 0.000001) {
                    continue;
                }

                $requirements->push($locked->procurementRequirements()->create([
                    'order_line_id' => $line->id,
                    'product_variant_id' => $variantId,
                    'required_base_quantity' => $shortage,
                    'fulfilled_base_quantity' => 0,
                    'status' => 'open',
                ]));
            }

            if ($requirements->isNotEmpty()) {
                $locked->forceFill([
                    'status' => 'pending_supplier_confirmation',
                    'pending_reason' => 'Insufficient stock. Procurement is required before fulfillment can continue.',
                    'updated_by' => $actor->getKey(),
                ])->save();

                activity()->performedOn($locked)->causedBy($actor)
                    ->withProperties([
                        'source_channel' => 'dashboard',
                        'shortage_count' => $requirements->count(),
                    ])
                    ->log('sales.order.procurement_required');
            }

            return $requirements;
        }, attempts: 5);
    }

    public function requestSupplierConfirmation(User $actor, Order $order, int $supplierId): SupplierConfirmation
    {
        return DB::transaction(function () use ($actor, $order, $supplierId): SupplierConfirmation {
            /** @var Order $locked */
            $locked = Order::query()->with(['customer', 'procurementRequirements'])
                ->whereKey($order->getKey())->lockForUpdate()->sole();

            $requirements = $locked->procurementRequirements()
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->lockForUpdate()
                ->get();

            if ($requirements->isEmpty()) {
                throw new DomainException('This sales order has no unresolved procurement shortage.');
            }

            $variantIds = $requirements
                ->pluck('product_variant_id')
                ->map(fn (mixed $id): int => $this->integerId($id))
                ->unique()
                ->values()
                ->all();

            if (! in_array($supplierId, $this->supplierSupport->eligibleSupplierIds(array_values($variantIds)), true)) {
                throw new DomainException('The selected supplier cannot supply every outstanding shortage variant.');
            }

            $items = $requirements
                ->groupBy('product_variant_id')
                ->map(fn (Collection $rows, int $variantId): array => [
                    'product_variant_id' => $variantId,
                    'requested_quantity' => (float) $rows->sum(
                        fn (SalesProcurementRequirement $row): float => (float) $row->outstandingBaseQuantity(),
                    ),
                ])
                ->values()
                ->all();

            $confirmation = $this->confirmations->recordItems(
                $actor,
                new SupplierConfirmationRequestData(
                    target: $locked,
                    customer: $locked->customer,
                    supplierId: $supplierId,
                    items: array_values($items),
                    notes: "Stock shortage for sales order {$locked->order_number}.",
                ),
            );

            $requirements->each(function (SalesProcurementRequirement $requirement) use ($confirmation): void {
                $requirement->forceFill([
                    'supplier_confirmation_id' => $confirmation->getKey(),
                    'status' => 'pending_confirmation',
                ])->save();
            });

            return $confirmation;
        }, attempts: 5);
    }

    public function createPurchaseOrder(
        User $actor,
        Order $order,
        int $supplierId,
        int $warehouseId,
        string $currencyCode = 'USD',
    ): PurchaseOrder {
        return DB::transaction(function () use ($actor, $order, $supplierId, $warehouseId, $currencyCode): PurchaseOrder {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->sole();

            $confirmation = $locked->confirmations()
                ->where('supplier_id', $supplierId)
                ->whereIn('confirmation_status', [
                    SupplierConfirmationStatus::Confirmed->value,
                    SupplierConfirmationStatus::Partial->value,
                ])
                ->latest('id')
                ->first();

            if (! $confirmation instanceof SupplierConfirmation) {
                throw new DomainException('A confirmed supplier response is required before creating the purchase order.');
            }

            $confirmedVariantIds = $confirmation->items()
                ->where('confirmation_status', SupplierConfirmationStatus::Confirmed->value)
                ->pluck('product_variant_id')
                ->map(fn (mixed $id): int => $this->integerId($id))
                ->unique()
                ->values()
                ->all();

            $requirementsQuery = $locked->procurementRequirements()
                ->where('supplier_confirmation_id', $confirmation->getKey())
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->whereNull('purchase_order_id');

            if ($confirmation->items()->exists()) {
                if ($confirmedVariantIds === []) {
                    throw new DomainException('The supplier has not confirmed any outstanding shortage variant.');
                }

                $requirementsQuery->whereIn('product_variant_id', $confirmedVariantIds);
            }

            $requirements = $requirementsQuery
                ->with('productVariant.variantUnits')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($requirements->isEmpty()) {
                throw new DomainException('There are no supplier-confirmed unpurchased shortage lines.');
            }

            $variantIds = $requirements
                ->pluck('product_variant_id')
                ->map(fn (mixed $id): int => $this->integerId($id))
                ->unique()
                ->values()
                ->all();

            if (! in_array($supplierId, $this->supplierSupport->eligibleSupplierIds(array_values($variantIds)), true)) {
                throw new DomainException('The confirmed supplier is no longer eligible for every shortage variant.');
            }

            $purchaseOrder = $this->purchaseOrders->createDraft($actor, [
                'supplier_id' => $supplierId,
                'destination_warehouse_id' => $warehouseId,
                'currency_code' => $currencyCode,
                'ordered_at' => now()->toDateString(),
                'expected_at' => $confirmation->promised_at?->toDateString(),
                'notes' => "Created for sales order {$locked->order_number}.",
            ]);

            foreach ($requirements as $requirement) {
                $variant = $requirement->productVariant;

                if (! $variant instanceof ProductVariant) {
                    throw new DomainException('A procurement requirement requires a product variant.');
                }

                $purchaseUnit = $this->purchaseUnit($variant);
                $factor = (float) $purchaseUnit->factor_to_base;
                $quantity = (float) $requirement->outstandingBaseQuantity() / $factor;

                $purchaseLine = $this->purchaseOrders->addLine($actor, $purchaseOrder, [
                    'product_variant_id' => $variant->id,
                    'unit_id' => $purchaseUnit->unit_id,
                    'quantity_ordered' => $quantity,
                    'expected_at' => $confirmation->promised_at?->toDateString(),
                ]);

                $requirement->forceFill([
                    'destination_warehouse_id' => $warehouseId,
                    'purchase_order_id' => $purchaseOrder->getKey(),
                    'purchase_order_line_id' => $purchaseLine->getKey(),
                    'status' => 'purchasing',
                ])->save();
            }

            $locked->forceFill([
                'pending_reason' => 'Purchase order created. Fulfillment remains blocked until the required receipt is completed.',
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'purchase_order_id' => $purchaseOrder->getKey(),
                ])
                ->log('sales.order.purchase_order_created');

            return $purchaseOrder->refresh()->load('lines');
        }, attempts: 5);
    }

    public function refreshFromPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder): void {
            $requirements = SalesProcurementRequirement::query()
                ->where('purchase_order_id', $purchaseOrder->getKey())
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($requirements as $requirement) {
                $line = PurchaseOrderLine::query()->find($requirement->purchase_order_line_id);
                $receivedQuantity = $line instanceof PurchaseOrderLine
                    ? (float) $line->received_base_quantity
                    : 0.0;
                $received = min((float) $requirement->required_base_quantity, $receivedQuantity);

                $requirement->forceFill([
                    'fulfilled_base_quantity' => round($received, 6),
                    'status' => $received + 0.000001 >= (float) $requirement->required_base_quantity
                        ? 'fulfilled'
                        : 'purchasing',
                ])->save();
            }

            $orderIds = $requirements->pluck('order_id')->unique();

            foreach ($orderIds as $orderId) {
                /** @var Order|null $order */
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

                if (! $order instanceof Order) {
                    continue;
                }

                $remaining = $order->procurementRequirements()
                    ->whereNotIn('status', ['fulfilled', 'cancelled'])
                    ->exists();

                if (! $remaining) {
                    $order->forceFill([
                        'status' => 'ready',
                        'pending_reason' => null,
                    ])->save();

                    activity()->performedOn($order)
                        ->withProperties(['source_channel' => 'inventory_receipt'])
                        ->log('sales.order.procurement_fulfilled');
                }
            }
        }, attempts: 5);
    }

    private function purchaseUnit(ProductVariant $variant): ProductVariantUnit
    {
        /** @var ProductVariantUnit|null $unit */
        $unit = $variant->variantUnits
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->sortByDesc('is_base')
            ->first();

        if (! $unit instanceof ProductVariantUnit || (float) $unit->factor_to_base <= 0.0) {
            throw new DomainException("Variant {$variant->sku} has no active purchase UOM.");
        }

        return $unit;
    }

    private function integerId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new DomainException('Expected an integer identifier.');
    }

    private function decimalValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : 0.0;
    }
}
