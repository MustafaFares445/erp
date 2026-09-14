<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SalesDemandProcurementService
{
    public function __construct(
        private PurchaseOrderService $purchaseOrders,
        private SupplierSupportResolver $supplierSupport,
    ) {}

    /** @return list<int> */
    public function eligibleSupplierIds(Order $order): array
    {
        $variantIds = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->pluck('product_variant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->supplierSupport->eligibleSupplierIds(array_values($variantIds));
    }

    /**
     * Create Purchasing-owned PO drafts for open Sales requirements. A separate
     * draft is used for each requirement so the existing PO invariant of one
     * variant/UOM line per document remains intact while provenance stays 1:1.
     * Logistics assigns inbound warehouses only after PO acceptance.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function createDrafts(User $actor, Order $order, int $supplierId, string $currencyCode = 'USD'): Collection
    {
        return DB::transaction(function () use ($actor, $order, $supplierId, $currencyCode): Collection {
            $requirements = $order->procurementRequirements()
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->whereNull('purchase_order_id')
                ->with('productVariant.variantUnits')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($requirements->isEmpty()) {
                throw new DomainException('There are no open Sales procurement requirements.');
            }

            $variantIds = $requirements->pluck('product_variant_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
            if (! in_array($supplierId, $this->supplierSupport->eligibleSupplierIds(array_values($variantIds)), true)) {
                throw new DomainException('The selected supplier cannot supply every selected Sales demand line.');
            }

            $created = new Collection;
            foreach ($requirements as $requirement) {
                $variant = $requirement->productVariant;
                if (! $variant instanceof ProductVariant) {
                    throw new DomainException('A procurement requirement requires a product variant.');
                }
                $unit = $this->purchaseUnit($variant);
                $factor = (float) $unit->factor_to_base;
                $purchaseOrder = $this->purchaseOrders->createDraft($actor, [
                    'supplier_id' => $supplierId,
                    'currency_code' => $currencyCode,
                    'ordered_at' => now()->toDateString(),
                    'notes' => "Created from Sales demand {$order->order_number}, requirement #{$requirement->id}.",
                ]);
                $purchaseLine = $this->purchaseOrders->addLine($actor, $purchaseOrder, [
                    'product_variant_id' => $variant->id,
                    'unit_id' => $unit->unit_id,
                    'quantity_ordered' => (float) $requirement->outstandingBaseQuantity() / $factor,
                ]);
                $requirement->forceFill([
                    'destination_warehouse_id' => null,
                    'supplier_confirmation_id' => null,
                    'purchase_order_id' => $purchaseOrder->getKey(),
                    'purchase_order_line_id' => $purchaseLine->getKey(),
                    'status' => 'purchasing',
                ])->save();
                $created->push($purchaseOrder->refresh()->load('lines'));
            }

            return $created;
        }, attempts: 5);
    }

    private function purchaseUnit(ProductVariant $variant): ProductVariantUnit
    {
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
}
