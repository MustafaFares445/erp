<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\SupplierProductReference;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SalesDemandProcurementService
{
    public function __construct(
        private PurchaseOrderService $purchaseOrders,
    ) {}

    /** @return list<int> */
    public function eligibleSupplierIds(Order $order, ?string $currencyCode = null): array
    {
        $variantIds = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->whereNull('purchase_order_id')
            ->pluck('product_variant_id')
            ->map(static fn (mixed $id): int => self::integerId($id))
            ->unique()
            ->values()
            ->all();

        if ($variantIds === []) {
            return [];
        }

        $requiredVariantCount = count($variantIds);
        $query = SupplierProductReference::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('availability_status', 'active')
            ->where('is_active', true)
            ->currentlyValid()
            ->whereHas('supplier', static fn (Builder $supplier): Builder => $supplier->where('is_active', true));

        if (is_string($currencyCode) && $currencyCode !== '') {
            $query->where('currency_code', mb_strtoupper($currencyCode));
        }

        $supplierIds = $query
            ->selectRaw('supplier_id, COUNT(DISTINCT product_variant_id) AS supported_variant_count')
            ->groupBy('supplier_id')
            ->havingRaw('COUNT(DISTINCT product_variant_id) = ?', [$requiredVariantCount])
            ->pluck('supplier_id')
            ->map(static fn (mixed $id): int => self::integerId($id))
            ->all();

        return array_values($supplierIds);
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
        return DB::transaction(
            fn (): Collection => $this->createDraftsWithinTransaction($actor, $order, $supplierId, $currencyCode),
            attempts: 5,
        );
    }

    /** @return Collection<int, PurchaseOrder> */
    private function createDraftsWithinTransaction(
        User $actor,
        Order $order,
        int $supplierId,
        string $currencyCode,
    ): Collection {
        $requirements = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->whereNull('purchase_order_id')
            ->with('productVariant.variantUnits')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($requirements->isEmpty()) {
            throw new DomainException('There are no open Sales procurement requirements.');
        }

        foreach ($requirements as $requirement) {
            if (($requirement->productVariant instanceof ProductVariant) === false) {
                throw new DomainException('A procurement requirement requires a product variant.');
            }
        }

        if (in_array($supplierId, $this->eligibleSupplierIds($order, $currencyCode), true) === false) {
            throw new DomainException('The selected supplier does not have an active Supplier Product in the selected currency for every open Sales demand line.');
        }

        $created = new Collection;

        foreach ($requirements as $requirement) {
            /** @var ProductVariant $variant */
            $variant = $requirement->productVariant;
            $reference = $this->purchaseOrders->referenceFor($supplierId, self::integerId($variant->getKey()));

            if (! $reference instanceof SupplierProductReference) {
                throw new DomainException("Supplier reference disappeared for variant {$variant->sku}.");
            }

            $unit = $this->purchaseUnit($variant, $reference);
            $quantity = $this->purchaseQuantity($requirement->outstandingBaseQuantity(), $unit, $reference);
            $purchaseOrder = $this->purchaseOrders->createDraft($actor, [
                'supplier_id' => $supplierId,
                'currency_code' => $currencyCode,
                'ordered_at' => now()->toDateString(),
                'notes' => "Created from Sales demand {$order->order_number}, requirement #{$requirement->id}.",
            ]);
            $purchaseLine = $this->purchaseOrders->addLine($actor, $purchaseOrder, [
                'product_variant_id' => $variant->id,
                'unit_id' => $unit->unit_id,
                'quantity_ordered' => $quantity,
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
    }

    private function purchaseUnit(ProductVariant $variant, SupplierProductReference $reference): ProductVariantUnit
    {
        $units = $variant->variantUnits
            ->where('is_active', true)
            ->where('is_purchase', true);
        $unit = $reference->purchase_unit_id !== null
            ? $units->firstWhere('unit_id', (int) $reference->purchase_unit_id)
            : $units->sortByDesc('is_base')->first();

        if (($unit instanceof ProductVariantUnit) === false || (float) $unit->factor_to_base <= 0.0) {
            throw new DomainException("Variant {$variant->sku} has no active purchase UOM.");
        }

        return $unit;
    }

    /** @param numeric-string $baseQuantity
     * @return numeric-string
     */
    private function purchaseQuantity(
        string $baseQuantity,
        ProductVariantUnit $unit,
        SupplierProductReference $reference,
    ): string {
        $factor = (string) $unit->factor_to_base;
        $increment = (string) $unit->rounding_increment;

        if (bccomp($factor, '0.000000', 6) <= 0 || bccomp($increment, '0.000000', 6) <= 0) {
            throw new DomainException('Supplier purchase UOM conversion must be positive.');
        }

        $target = bcdiv($baseQuantity, $factor, 12);

        if ($reference->minimum_order_quantity !== null
            && bccomp((string) $reference->minimum_order_quantity, $target, 12) === 1) {
            $target = (string) $reference->minimum_order_quantity;
        }

        $multiple = bcdiv($target, $increment, 12);
        $whole = bcadd($multiple, '0', 0);

        if (bccomp($multiple, $whole, 12) === 1) {
            $whole = bcadd($whole, '1', 0);
        }

        return bcadd(bcmul($whole, $increment, 12), '0', 6);
    }

    private static function integerId(mixed $value): int
    {
        if (is_numeric($value) === false) {
            throw new DomainException('A procurement requirement must have a numeric product identifier.');
        }

        return (int) $value;
    }
}
