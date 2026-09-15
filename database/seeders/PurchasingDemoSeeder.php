<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\PurchaseOrderDraftBillService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Demo purchasing data: an order in every status, confirmations against both
 * document types, and one order carrying a received line.
 *
 * Idempotent by purchase order number, so re-running neither duplicates nor
 * rewrites. Received scenarios use the canonical receiving services so their
 * inbound, receipt, stock, and purchase-order projections remain connected.
 */
final class PurchasingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::query()->where('is_active', true)->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $variant = ProductVariant::query()
            ->with('unit')
            ->whereHas('product', static fn (Builder $query): Builder => $query->where('product_type', ProductType::Grain->value))
            ->first();
        $unit = $variant?->unit;

        if (! $supplier instanceof Supplier
            || ! $warehouse instanceof Warehouse
            || ! $variant instanceof ProductVariant
            || ! $unit instanceof Unit) {
            // Nothing to hang purchasing data off. The inventory demo seeder
            // runs first in DatabaseSeeder; if it was skipped, skip too rather
            // than inventing a catalogue.
            return;
        }

        PurchaseSetting::current();

        $buyer = User::query()->where('email', 'admin@ierp.com')->first();

        $this->seedReference($supplier, $variant);

        foreach ($this->orderBlueprints() as $index => [$status, $received, $sent]) {
            $order = $this->seedOrder(
                sprintf('PO-DEMO%02d', $index + 1),
                $status,
                $sent,
                $supplier,
                $buyer,
            );

            $this->removeUnreceivedDuplicateLines($order);
            $this->seedLine($order, $variant, $unit, $received);
            $this->seedAllocation($order, $warehouse, $buyer);
            $this->seedPhysicalReceipt($order, $received, $buyer);
        }

        $this->seedConfirmations($supplier);
        $this->seedDraftBill($buyer);
    }

    /**
     * One order per status, so every badge and filter has something behind it.
     * `Accepted` appears twice — once not yet communicated to the supplier,
     * once with `sent_at` recorded — since sending is metadata layered on top
     * of acceptance rather than a status of its own (Phase 0 remediation).
     *
     * @return list<array{0: PurchaseOrderStatus, 1: float, 2: bool}>
     */
    private function orderBlueprints(): array
    {
        return [
            [PurchaseOrderStatus::Draft, 0, false],
            [PurchaseOrderStatus::PendingApproval, 0, false],
            [PurchaseOrderStatus::Accepted, 0, false],
            [PurchaseOrderStatus::Rejected, 0, false],
            [PurchaseOrderStatus::Accepted, 0, true],
            [PurchaseOrderStatus::PartiallyReceived, 4, true],
            [PurchaseOrderStatus::Received, 10, true],
            [PurchaseOrderStatus::Closed, 6, true],
            [PurchaseOrderStatus::Cancelled, 0, false],
        ];
    }

    private function seedOrder(
        string $number,
        PurchaseOrderStatus $status,
        bool $sent,
        Supplier $supplier,
        ?User $buyer,
    ): PurchaseOrder {
        /** @var PurchaseOrder $order */
        $order = PurchaseOrder::withTrashed()->firstOrNew(['purchase_order_number' => $number]);

        $order->forceFill([
            'purchase_order_number' => $number,
            'supplier_id' => $supplier->getKey(),
            'status' => $status,
            'currency_code' => 'AED',
            'ordered_at' => now()->subDays(14)->toDateString(),
            'expected_at' => now()->addDays(7)->toDateString(),
            'total_amount' => '250.00',
            'submitted_by' => $status === PurchaseOrderStatus::Draft ? null : $buyer?->getKey(),
            'submitted_at' => $status === PurchaseOrderStatus::Draft ? null : now()->subDays(13),
            'approved_by' => $this->isApproved($status) ? $buyer?->getKey() : null,
            'approved_at' => $this->isApproved($status) ? now()->subDays(12) : null,
            'sent_at' => $sent ? now()->subDays(11) : null,
            'closed_at' => $status === PurchaseOrderStatus::Closed ? now()->subDay() : null,
            'closure_reason' => $status === PurchaseOrderStatus::Closed ? 'Supplier discontinued the remaining line.' : null,
            'cancelled_at' => $status === PurchaseOrderStatus::Cancelled ? now()->subDays(10) : null,
            'cancellation_reason' => $status === PurchaseOrderStatus::Cancelled ? 'Ordered in error.' : null,
            'rejection_reason' => $status === PurchaseOrderStatus::Rejected ? 'Quoted above budget.' : null,
            'created_by' => $buyer?->getKey(),
            'updated_by' => $buyer?->getKey(),
        ])->save();

        return $order->refresh();
    }

    private function seedLine(PurchaseOrder $order, ProductVariant $variant, Unit $unit, float $received): void
    {
        $unitId = $unit->getKey();

        if (! is_int($unitId)) {
            throw new LogicException('Purchasing demo units require integer identifiers.');
        }

        $normalizer = app(QuantityNormalizer::class);
        $ordered = $normalizer->normalize($variant, $unitId, '10');
        $receivedTransactionQuantity = '0.000000';
        $receivedBaseQuantity = '0.000000';

        if ($received > 0) {
            $receivedSnapshot = $normalizer->normalize(
                $variant,
                $unitId,
                mb_rtrim(mb_rtrim(number_format($received, 6, '.', ''), '0'), '.'),
            );
            $receivedTransactionQuantity = $receivedSnapshot->transactionQuantity;
            $receivedBaseQuantity = $receivedSnapshot->baseQuantity;
        }

        $line = $order->lines()->oldest('id')->first() ?? $order->lines()->make();

        $line->forceFill([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $ordered->transactionUnitId,
            'quantity_ordered' => $ordered->transactionQuantity,
            'quantity_received' => $receivedTransactionQuantity,
            'transaction_quantity' => $ordered->transactionQuantity,
            'transaction_unit_id' => $ordered->transactionUnitId,
            'conversion_factor_snapshot' => $ordered->conversionFactorSnapshot,
            'base_quantity' => $ordered->baseQuantity,
            'received_base_quantity' => $receivedBaseQuantity,
            'unit_cost' => '25.00',
            'last_received_unit_cost' => $received > 0 ? '26.50' : null,
            'line_total' => '250.00',
        ])->save();
    }

    /**
     * Allocates every accepted-or-later demo order to the demo warehouse
     * (Phase 0 remediation: a purchase order no longer carries its own
     * warehouse, so the demo data has to allocate one explicitly, the same
     * way a real Inventory Manager would).
     */
    private function seedAllocation(PurchaseOrder $order, Warehouse $warehouse, ?User $buyer): void
    {
        if (! $order->status->isAcceptedOrLater() || ! $buyer instanceof User) {
            return;
        }

        app(PurchaseInboundService::class)->allocateAllTo($buyer, $order, $warehouse);
    }

    private function removeUnreceivedDuplicateLines(PurchaseOrder $order): void
    {
        if (InventoryOperation::query()
            ->where('operation_type', 'receipt')
            ->where('source_document_type', PurchaseOrder::class)
            ->where('source_document_id', $order->getKey())
            ->where('stage', 'done')
            ->exists()) {
            return;
        }

        $lineIds = $order->lines()->oldest('id')->pluck('id');
        $duplicateLineIds = $lineIds->slice(1)->values();

        if ($duplicateLineIds->isEmpty()) {
            return;
        }

        $inboundLineIds = PurchaseInboundLine::query()
            ->whereIn('purchase_order_line_id', $duplicateLineIds)
            ->pluck('id');

        PurchaseInboundAllocation::query()->whereIn('purchase_inbound_line_id', $inboundLineIds)->delete();
        PurchaseInboundLine::query()->whereIn('id', $inboundLineIds)->delete();
        InventoryOperationLine::query()->whereIn('purchase_order_line_id', $duplicateLineIds)->delete();
        $order->lines()->whereIn('id', $duplicateLineIds)->delete();
    }

    private function seedPhysicalReceipt(PurchaseOrder $order, float $received, ?User $buyer): void
    {
        if ($received <= 0 || ! $buyer instanceof User) {
            return;
        }

        if (InventoryOperation::query()
            ->where('operation_type', 'receipt')
            ->where('source_document_type', PurchaseOrder::class)
            ->where('source_document_id', $order->getKey())
            ->where('stage', 'done')
            ->exists()) {
            return;
        }

        $order->forceFill(['status' => PurchaseOrderStatus::Accepted])->save();
        $order->lines()->update([
            'quantity_received' => '0.000000',
            'received_base_quantity' => '0.000000',
        ]);

        $inbound = $order->purchaseInbound()->firstOrFail();
        $allocation = $inbound->lines()->firstOrFail()->allocations()->firstOrFail();
        $allocationId = $allocation->getKey();

        if (! is_int($allocationId)) {
            throw new LogicException('Purchasing demo allocation identifiers must be integers.');
        }

        $receipt = app(PurchaseOrderReceivingService::class)->initiate($buyer, $order->refresh(), [[
            'purchase_inbound_allocation_id' => $allocationId,
            'quantity' => $this->normalizedQuantity($received),
        ]]);

        $operations = app(InventoryOperationService::class);
        $operations->markReady($receipt, $buyer);
        $operations->complete($receipt->refresh(), $buyer);

        if ($order->purchase_order_number === 'PO-DEMO08') {
            $order->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'closed_at' => now()->subDay(),
                'closure_reason' => 'Supplier discontinued the remaining line.',
            ])->save();
        }
    }

    private function normalizedQuantity(float $quantity): string
    {
        return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    private function seedReference(Supplier $supplier, ProductVariant $variant): void
    {
        SupplierProductReference::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->getKey(),
                'product_variant_id' => $variant->getKey(),
            ],
            [
                'supplier_item_number' => 'DEMO-'.$variant->id,
                'purchase_cost' => '25.00',
                'currency_code' => 'AED',
                'is_active' => true,
            ],
        );
    }

    /** Seed PO-only supplier confirmation evidence. */
    private function seedConfirmations(Supplier $supplier): void
    {
        $purchaseOrder = PurchaseOrder::query()->where('purchase_order_number', 'PO-DEMO05')->first();

        if ($purchaseOrder instanceof PurchaseOrder) {
            $this->seedConfirmation($purchaseOrder, $supplier, SupplierConfirmationStatus::Confirmed, 'Confirmed by phone.');
        }
    }

    private function seedDraftBill(?User $buyer): void
    {
        if (! $buyer instanceof User) {
            return;
        }

        $order = PurchaseOrder::query()
            ->where('purchase_order_number', 'PO-DEMO05')
            ->where('status', PurchaseOrderStatus::Accepted)
            ->first();

        if ($order instanceof PurchaseOrder) {
            app(PurchaseOrderDraftBillService::class)->ensureForAccepted($buyer, $order);
        }
    }

    private function seedConfirmation(
        PurchaseOrder $purchaseOrder,
        Supplier $supplier,
        SupplierConfirmationStatus $status,
        string $notes,
    ): void {
        SupplierConfirmation::query()->updateOrCreate(
            [
                'purchase_order_id' => $purchaseOrder->getKey(),
                'supplier_id' => $supplier->getKey(),
            ],
            [
                'confirmation_status' => $status,
                'promised_at' => $status->isAnswered() ? now()->addDays(5)->toDateString() : null,
                'confirmed_at' => $status->isAnswered() ? now()->subDays(2) : null,
                'notes' => $notes,
            ],
        );
    }

    private function isApproved(PurchaseOrderStatus $status): bool
    {
        return ! in_array($status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Rejected,
        ], true);
    }
}
