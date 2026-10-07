<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PaymentTerm;
use App\Models\ProductVariant;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseAgreementLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use App\Services\Settings\CurrencyCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Draft purchase order writes: header, lines, cost defaulting, and totals.
 *
 * Everything here refuses to touch an order that has left draft (V-06, FR-025).
 * That guard lives in {@see self::assertEditable()} and is called by every
 * mutating method, so there is one place to read rather than one rule repeated
 * at six call sites.
 *
 * Totals are stored, not derived (R-008). The open-commitments report
 * aggregates across every non-terminal order, and a computed accessor would
 * make that unindexable; storing them also makes "the number that was approved
 * is the number that is stored" trivially true.
 *
 * @see /Docs/domains/purchasing/README.md §2
 */
final readonly class PurchaseOrderService
{
    public function __construct(
        private PurchaseOrderNumberGenerator $numbers,
        private QuantityNormalizer $quantityNormalizer,
        private CurrencyCatalogService $currencies,
        private PurchaseAgreementPriceResolver $agreementPrices,
    ) {}

    /**
     * @param  array{supplier_id: int, currency_code?: string|null, payment_term_id?: int|null, ordered_at: string, expected_at?: string|null, notes?: string|null}  $attributes
     */
    public function createDraft(User $actor, array $attributes): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('create', PurchaseOrder::class);

        return DB::transaction(function () use ($actor, $attributes): PurchaseOrder {
            $supplier = $this->assertSupplierIsUsable((int) $attributes['supplier_id']);
            $currencyCode = $attributes['currency_code'] ?? $supplier->default_currency_code ?? $this->currencies->defaultCode();
            $paymentTermId = $attributes['payment_term_id'] ?? $supplier->payment_term_id;
            $this->assertPaymentTermExists($paymentTermId);

            $order = new PurchaseOrder([
                'supplier_id' => $attributes['supplier_id'],
                'currency_code' => $this->currencies->normalizeActive((string) $currencyCode, 'currency_code'),
                'payment_term_id' => $paymentTermId,
                'ordered_at' => $attributes['ordered_at'],
                'expected_at' => $attributes['expected_at'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $order->forceFill([
                'purchase_order_number' => $this->numbers->next(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Creates one complete draft document atomically from the create-page payload.
     *
     * The existing createDraft() and addLine() methods remain the canonical
     * contracts: this method only orchestrates them inside one outer transaction,
     * so supplier validation, purchase-UOM normalization, supplier-reference
     * provenance, cost defaulting, duplicate checks, and total recomputation are
     * not duplicated here. Any rejected line rolls back the header and every
     * line added before it.
     *
     * @param  array{supplier_id: int, currency_code?: string|null, payment_term_id?: int|null, ordered_at: string, expected_at?: string|null, notes?: string|null}  $attributes
     * @param  list<array{product_variant_id: int, unit_id: int, quantity_ordered: float|string, unit_cost?: float|string|null}>  $lines
     */
    public function createDraftWithLines(User $actor, array $attributes, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $attributes, $lines): PurchaseOrder {
            $order = $this->createDraft($actor, $attributes);

            if ($lines === []) {
                throw InvalidPurchaseOrderLine::noLines($order->purchase_order_number);
            }

            foreach ($lines as $line) {
                $this->addLine($actor, $order, $line);
            }

            return $order->refresh()->load('lines');
        });
    }

    /**
     * @param  array{supplier_id?: int, currency_code?: string, payment_term_id?: int|null, ordered_at?: string, expected_at?: string|null, notes?: string|null}  $attributes
     */
    public function updateDraft(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $attributes): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            if (isset($attributes['supplier_id'])) {
                $supplierId = (int) $attributes['supplier_id'];
                $supplier = $this->assertSupplierIsUsable($supplierId);

                if ($supplierId !== (int) $locked->supplier_id && $locked->lines()->exists()) {
                    throw InvalidPurchaseOrderLine::supplierChangeRequiresEmptyOrder();
                }

                $attributes['supplier_id'] = $supplierId;

                if (! array_key_exists('payment_term_id', $attributes)) {
                    $attributes['payment_term_id'] = $supplier->payment_term_id;
                }
            }

            if (array_key_exists('payment_term_id', $attributes)) {
                $paymentTermId = $attributes['payment_term_id'];
                $this->assertPaymentTermExists(is_numeric($paymentTermId) ? (int) $paymentTermId : null);
                $attributes['payment_term_id'] = is_numeric($paymentTermId) ? (int) $paymentTermId : null;
            }

            if (isset($attributes['currency_code'])) {
                $currency = $this->currencies->normalizeActive((string) $attributes['currency_code'], 'currency_code');

                if (mb_strtoupper($currency) !== mb_strtoupper((string) $locked->currency_code)
                    && $locked->lines()->exists()) {
                    throw InvalidPurchaseOrderLine::currencyChangeRequiresEmptyOrder();
                }

                $attributes['currency_code'] = $currency;
            }

            $locked->fill($attributes);
            $locked->forceFill(['updated_by' => $actor->getKey()])->save();

            return $locked->refresh();
        });
    }

    /**
     * Adds a line only when the selected supplier has an active product
     * reference for the variant. The reference remains the commercial source of
     * truth even when the buyer overrides the defaulted price manually.
     *
     * @param  array{product_variant_id: int, unit_id: int, quantity_ordered: float|string, unit_cost?: float|string|null}  $attributes
     */
    public function addLine(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrderLine
    {
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $attributes): PurchaseOrderLine {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $variantId = (int) $attributes['product_variant_id'];
            $unitId = (int) $attributes['unit_id'];
            $quantityInput = $this->quantityInput($attributes['quantity_ordered']);
            $quantity = (float) $quantityInput;

            $this->assertQuantityIsPositive($quantity);
            $this->assertVariantIsNotAlreadyOnOrder($locked, $variantId, $unitId);

            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->findOrFail($variantId);
            $this->assertPurchaseUnit($variant, $unitId);
            $reference = $this->requireSupplierReference($locked, $variant);
            $this->assertSupplierReferenceTerms($reference, $variant, $unitId, $quantityInput);
            $snapshot = $this->quantityNormalizer->normalize($variant, $unitId, $quantityInput);
            $unitCost = $this->resolveUnitCost(
                $attributes['unit_cost'] ?? null,
                $reference,
                $snapshot->conversionFactorSnapshot,
                $locked->currency_code,
                $locked->supplier_id,
                $variantId,
                $unitId,
            );

            $line = new PurchaseOrderLine([
                'purchase_order_id' => $locked->getKey(),
                'product_variant_id' => $variantId,
                'unit_id' => $unitId,
                'supplier_product_reference_id' => $reference->getKey(),
                'supplier_item_number' => $reference->supplier_item_number,
                'quantity_ordered' => $snapshot->transactionQuantity,
                'unit_cost' => $unitCost,
            ]);

            $line->forceFill([
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'received_base_quantity' => '0.000000',
                'line_total' => $this->lineTotal((float) $snapshot->transactionQuantity, $unitCost),
            ])->save();

            $this->recomputeTotal($locked, $actor);

            return $line->refresh();
        });
    }

    /**
     * @param  array{quantity_ordered?: float|string, unit_cost?: float|string}  $attributes
     */
    public function updateLine(User $actor, PurchaseOrderLine $line, array $attributes): PurchaseOrderLine
    {
        $order = $line->purchaseOrder;
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $line, $attributes): PurchaseOrderLine {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $quantityInput = $this->quantityInput($attributes['quantity_ordered'] ?? $line->quantity_ordered);
            $quantity = (float) $quantityInput;
            $unitCost = (float) ($attributes['unit_cost'] ?? $line->unit_cost);

            $this->assertQuantityIsPositive($quantity);
            $this->assertUnitCostIsNotNegative($unitCost);

            $variant = $line->productVariant;
            $this->assertPurchaseUnit($variant, $line->unit_id);
            $snapshot = $this->quantityNormalizer->normalize($variant, $line->unit_id, $quantityInput);
            $unitCost = $this->storedCost($unitCost);

            $line->fill([
                'quantity_ordered' => $snapshot->transactionQuantity,
                'unit_cost' => $unitCost,
            ]);

            $line->forceFill([
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'line_total' => $this->lineTotal((float) $snapshot->transactionQuantity, $unitCost),
            ])->save();

            $this->recomputeTotal($locked, $actor);

            return $line->refresh();
        });
    }

    public function removeLine(User $actor, PurchaseOrderLine $line): void
    {
        $order = $line->purchaseOrder;
        Gate::forUser($actor)->authorize('update', $order);

        DB::transaction(function () use ($actor, $order, $line): void {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $line->delete();

            $this->recomputeTotal($locked, $actor);
        });
    }

    /**
     * Recomputes the order's stored total from its stored line totals.
     *
     * Summing the stored line totals rather than re-deriving each from quantity
     * and cost is what keeps the document total equal to the sum of the figures
     * printed on it, penny for penny.
     */
    public function recomputeTotal(PurchaseOrder $order, ?User $actor = null): PurchaseOrder
    {
        $total = (float) $order->lines()->sum('line_total');

        $order->forceFill([
            'total_amount' => round($total, 2),
            'updated_by' => $actor?->getKey() ?? $order->updated_by,
        ])->save();

        return $order->refresh();
    }

    /**
     * The single active reference for this supplier and variant, if any.
     *
     * A unique index guarantees there is at most one (V-14), so this never has
     * to choose between rows.
     */
    public function referenceFor(int $supplierId, int $productVariantId): ?SupplierProductReference
    {
        return SupplierProductReference::query()
            ->activeFor($supplierId, $productVariantId)
            ->first();
    }

    /** @return array<int, string> */
    public function supportedVariantOptions(PurchaseOrder $order): array
    {
        return SupplierProductReference::query()
            ->where('supplier_id', $order->supplier_id)
            ->where('availability_status', 'active')
            ->where('is_active', true)
            ->currentlyValid()
            ->with('productVariant:id,sku')
            ->orderByDesc('is_preferred')
            ->orderBy('supplier_item_number')
            ->get()
            ->mapWithKeys(static function (SupplierProductReference $reference): array {
                $variant = $reference->productVariant;

                if ($variant instanceof ProductVariant) {
                    $label = $variant->sku;

                    if ($reference->supplier_item_number !== '') {
                        $label .= ' — '.$reference->supplier_item_number;
                    }

                    return [$reference->product_variant_id => $label];
                }

                return [];
            })
            ->all();
    }

    /**
     * Revalidate mutable commercial master data immediately before a Draft PO
     * becomes a commitment.
     */
    public function assertCommercialReadiness(PurchaseOrder $order): void
    {
        $this->assertSupplierIsUsable((int) $order->supplier_id);

        $lines = $order->lines()->with(['productVariant', 'supplierProductReference'])->get();

        if ($lines->isEmpty()) {
            throw InvalidPurchaseOrderLine::noLines($order->purchase_order_number);
        }

        foreach ($lines as $line) {
            $variant = $line->productVariant;

            if (! $variant->is_active) {
                throw InvalidPurchaseOrderLine::unsupportedSupplierItem($order->supplier, $variant);
            }

            // New service-created lines always carry these provenance
            // snapshots. Legacy/imported rows may not, so revalidate only the
            // commercial facts that were actually snapshotted on the line.
            if ($line->transaction_unit_id !== null) {
                $this->assertPurchaseUnit($variant, (int) $line->unit_id);
            }

            if ($line->supplier_product_reference_id !== null) {
                $reference = $this->referenceFor((int) $order->supplier_id, (int) $line->product_variant_id);

                if (! $reference instanceof SupplierProductReference) {
                    throw InvalidPurchaseOrderLine::unsupportedSupplierItem($order->supplier, $variant);
                }

                $this->assertSupplierReferenceTerms(
                    $reference,
                    $variant,
                    (int) $line->unit_id,
                    (string) $line->quantity_ordered,
                );
            }

            $this->assertQuantityIsPositive((float) $line->quantity_ordered);
            $this->assertUnitCostIsNotNegative((float) $line->unit_cost);
        }
    }

    /**
     * @throws PurchaseOrderNotEditable
     */
    public function assertEditable(PurchaseOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw PurchaseOrderNotEditable::status($order);
        }
    }

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        return $locked;
    }

    private function requireSupplierReference(PurchaseOrder $order, ProductVariant $variant): SupplierProductReference
    {
        $reference = $this->referenceFor($order->supplier_id, $variant->id);

        if ($reference instanceof SupplierProductReference) {
            return $reference;
        }

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($order->supplier_id);

        throw InvalidPurchaseOrderLine::unsupportedSupplierItem($supplier, $variant);
    }

    private function assertSupplierReferenceTerms(
        SupplierProductReference $reference,
        ProductVariant $variant,
        int $unitId,
        string|int $quantity,
    ): void {
        if ($reference->purchase_unit_id !== null && (int) $reference->purchase_unit_id !== $unitId) {
            throw InvalidPurchaseOrderLine::supplierPurchaseUnitMismatch($variant);
        }

        if ($reference->minimum_order_quantity === null) {
            return;
        }

        if (! is_numeric($quantity)) {
            throw InvalidPurchaseOrderLine::quantityNotPositive();
        }

        /** @var numeric-string $minimumInput */
        $minimumInput = $reference->minimum_order_quantity;
        /** @var numeric-string $orderedInput */
        $orderedInput = (string) $quantity;
        $minimum = bcadd('0.000000', $minimumInput, 6);
        $ordered = bcadd('0.000000', $orderedInput, 6);

        if (bccomp($minimum, '0.000000', 6) === 1 && bccomp($ordered, $minimum, 6) === -1) {
            throw InvalidPurchaseOrderLine::minimumOrderQuantity($variant, $minimum);
        }
    }

    private function resolveUnitCost(
        float|string|null $given,
        SupplierProductReference $reference,
        string $conversionFactor,
        string $orderCurrency,
        int $supplierId,
        int $productVariantId,
        int $unitId,
    ): float {
        if ($given !== null) {
            $cost = (float) $given;
            $this->assertUnitCostIsNotNegative($cost);

            return $this->storedCost($cost);
        }

        $agreementLine = $this->agreementPrices->resolve(
            $supplierId,
            $productVariantId,
            $unitId,
            now(),
            $orderCurrency,
        );

        if ($agreementLine instanceof PurchaseAgreementLine) {
            $agreement = $agreementLine->agreement;
            if (! $agreement instanceof PurchaseAgreement) {
                throw new \DomainException('Purchase agreement line is not linked to an agreement.');
            }

            $agreementCurrency = mb_strtoupper((string) $agreement->currency_code);
            $normalizedOrderCurrency = mb_strtoupper($orderCurrency);

            if ($agreementCurrency !== $normalizedOrderCurrency) {
                throw InvalidPurchaseOrderLine::supplierReferenceCurrencyMismatch(
                    $agreementCurrency,
                    $normalizedOrderCurrency,
                );
            }

            $cost = (float) $agreementLine->unit_price;
        } else {
            $referenceCurrency = mb_strtoupper((string) $reference->currency_code);
            $normalizedOrderCurrency = mb_strtoupper($orderCurrency);

            if ($referenceCurrency !== $normalizedOrderCurrency) {
                throw InvalidPurchaseOrderLine::supplierReferenceCurrencyMismatch(
                    $referenceCurrency,
                    $normalizedOrderCurrency,
                );
            }

            $cost = (float) $reference->purchase_cost * (float) $conversionFactor;
        }

        $this->assertUnitCostIsNotNegative($cost);

        return $this->storedCost($cost);
    }

    /**
     * Rounds to the precision `unit_cost` actually stores.
     *
     * Without this, a cost of `33.333` is written to the column as `33.33` but
     * multiplied out as `33.333`, so the line total no longer equals the unit
     * cost times the quantity the buyer can see. The document total is the sum
     * of line totals, so the drift would surface on the printed order.
     */
    private function storedCost(float $unitCost): float
    {
        return round($unitCost, 2);
    }

    private function lineTotal(float $quantity, float $unitCost): float
    {
        return round($quantity * $this->storedCost($unitCost), 2);
    }

    private function assertPurchaseUnit(ProductVariant $variant, int $unitId): void
    {
        $allowed = $variant->variantUnits()
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->exists();

        if (! $allowed) {
            throw InvalidPurchaseOrderLine::invalidPurchaseUnit($variant);
        }
    }

    private function quantityInput(mixed $quantity): string|int
    {
        if (is_int($quantity) || is_string($quantity)) {
            return $quantity;
        }

        if (is_float($quantity) && is_finite($quantity)) {
            return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
        }

        throw InvalidPurchaseOrderLine::quantityNotPositive();
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertQuantityIsPositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw InvalidPurchaseOrderLine::quantityNotPositive();
        }
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertUnitCostIsNotNegative(float $unitCost): void
    {
        if ($unitCost < 0) {
            throw InvalidPurchaseOrderLine::unitCostNegative();
        }
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertVariantIsNotAlreadyOnOrder(PurchaseOrder $order, int $variantId, int $unitId): void
    {
        $exists = $order->lines()
            ->where('product_variant_id', $variantId)
            ->where('unit_id', $unitId)
            ->exists();

        if (! $exists) {
            return;
        }

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->findOrFail($variantId);

        throw InvalidPurchaseOrderLine::duplicateVariant($variant);
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertSupplierIsUsable(int $supplierId): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! $supplier->is_active) {
            throw InvalidPurchaseOrderLine::inactiveSupplier($supplier);
        }

        return $supplier;
    }

    private function assertPaymentTermExists(?int $paymentTermId): void
    {
        if ($paymentTermId === null) {
            return;
        }

        if (! PaymentTerm::query()->whereKey($paymentTermId)->exists()) {
            throw new \DomainException('The selected payment terms are not available.');
        }
    }
}
