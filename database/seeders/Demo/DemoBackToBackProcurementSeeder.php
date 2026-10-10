<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\SalesDemandProcurementService;
use App\Services\Purchasing\SupplierConfirmationService;
use App\Services\Sales\SalesProcurementRequirementService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * F-02 end-to-end proof: a released Sales order exceeds available stock,
 * Purchasing sources the generated requirement, Inventory receives it, and the
 * newly available quantity is then delivered to the customer.
 */
final class DemoBackToBackProcurementSeeder extends DemoSeeder
{
    private const string OrderMarker = '[DEMO-SALES] O14 -';

    protected function seed(DemoContext $context): void
    {
        $order = Order::query()->where('notes', 'like', self::OrderMarker.'%')->first();

        if (! $order instanceof Order) {
            $this->note('Skipping F-02 back-to-back procurement because O14 is not seeded.');

            return;
        }

        if ($order->procurementRequirements()
            ->where('status', 'fulfilled')
            ->whereNotNull('purchase_order_id')
            ->exists()) {
            $this->note('F-02 back-to-back procurement already completed.');

            return;
        }

        $requirement = $order->procurementRequirements()
            ->where('status', 'open')
            ->whereNull('purchase_order_id')
            ->orderBy('id')
            ->first();

        if ($requirement === null) {
            throw new RuntimeException('Demo O14 must expose an open Sales procurement requirement before F-02 sourcing.');
        }

        $context->at('2026-10-01 10:15:00');
        $procurement = app(SalesDemandProcurementService::class);
        $eligibleSupplierIds = $procurement->eligibleSupplierIds($order, 'AED');

        if ($eligibleSupplierIds === []) {
            throw new RuntimeException('No AED supplier can source the demo O14 Sales shortage.');
        }

        $supplier = Supplier::query()
            ->whereIn('id', $eligibleSupplierIds)
            ->orderBy('requires_confirmation')
            ->orderBy('id')
            ->firstOrFail();

        $officer = $context->as('purchasing_officer');
        $purchaseOrders = $procurement->createDrafts(
            $officer,
            $order->refresh(),
            DemoContext::keyOf($supplier),
            'AED',
        );

        foreach ($purchaseOrders as $index => $purchaseOrder) {
            $this->receivePurchaseOrder($context, $purchaseOrder, $index + 1);
        }

        $requirement->refresh();

        if ($requirement->status !== 'fulfilled') {
            throw new RuntimeException('The demo back-to-back receipt did not fulfill its linked Sales requirement.');
        }

        $context->at('2026-10-02 10:00:00');
        $kit = DemoSalesKit::make($context);
        $deliveries = $kit->plan(
            'operations',
            $order->refresh(),
            ['P018-LOWER' => 110],
        );

        $context->at('2026-10-02 11:00:00');
        foreach ($deliveries as $delivery) {
            $kit->prepare('operations', $delivery);
        }

        $context->at('2026-10-02 12:00:00');
        foreach ($deliveries as $index => $delivery) {
            $kit->dispatch('operations', $delivery->refresh(), sprintf('TRK-DEMO-B2B-%02d', $index + 1));
        }

        $context->at('2026-10-02 17:00:00');
        foreach ($deliveries as $delivery) {
            $kit->arrive('admin', $delivery->refresh(), 'Back-to-back stock received, dispatched, and accepted by the customer.');
        }

        $this->note('F-02 Sales shortage -> PO -> receipt -> outbound delivery completed for O14.');
    }

    private function receivePurchaseOrder(DemoContext $context, PurchaseOrder $purchaseOrder, int $index): void
    {
        $approval = app(PurchaseOrderApprovalService::class);

        $context->at('2026-10-01 10:30:00');
        $purchaseOrder = $approval->submit($context->as('purchasing_officer'), $purchaseOrder->refresh());

        if ($purchaseOrder->status === PurchaseOrderStatus::PendingApproval) {
            $context->at('2026-10-01 10:45:00');
            $purchaseOrder = $approval->approve($context->as('purchasing_manager'), $purchaseOrder->refresh());
        }

        $context->at('2026-10-01 11:00:00');
        $purchaseOrder = $approval->send($context->as('purchasing_manager'), $purchaseOrder->refresh());

        $confirmation = $purchaseOrder->confirmations()
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->latest('id')
            ->first();

        if ($confirmation !== null) {
            $items = [];
            foreach ($confirmation->items as $item) {
                $items[] = [
                    'id' => DemoContext::keyOf($item),
                    'confirmed_base_quantity' => (string) $item->requested_base_quantity,
                    'backordered_base_quantity' => '0.000000',
                    'promised_at' => '2026-10-02',
                ];
            }

            $context->at('2026-10-01 11:15:00');
            app(SupplierConfirmationService::class)->respond(
                $context->as('purchasing_officer'),
                $confirmation,
                SupplierConfirmationStatus::Confirmed,
                CarbonImmutable::parse('2026-10-02'),
                'Supplier confirmed the back-to-back quantity in full.',
                $items,
            );
        }

        $inventory = DemoInventory::make();
        $context->at('2026-10-01 11:30:00');
        app(PurchaseInboundService::class)->allocateAllTo(
            $context->as('operations'),
            $purchaseOrder->refresh(),
            $inventory->warehouse('WH-MAIN'),
        );

        $purchaseOrder->refresh()->load('purchaseInbound.lines.allocations');
        $receiving = app(PurchaseOrderReceivingService::class);
        $lines = [];

        foreach ($purchaseOrder->purchaseInbound->lines ?? [] as $inboundLine) {
            foreach ($inboundLine->allocations as $allocation) {
                $available = $receiving->availableBaseQuantityForAllocation($allocation);
                if ((float) $available <= 0.0) {
                    continue;
                }

                $lines[] = [
                    'purchase_inbound_allocation_id' => DemoContext::keyOf($allocation),
                    'quantity' => $available,
                ];
            }
        }

        if ($lines === []) {
            throw new RuntimeException('Back-to-back PO has no receivable inbound allocation.');
        }

        $context->at('2026-10-02 08:00:00');
        $operation = $receiving->initiate(
            $context->as('operations'),
            $purchaseOrder->refresh(),
            $lines,
        );
        $inventory->completeReceiptIdentity($operation->refresh(), sprintf('B2B-%02d', $index));

        $operations = app(InventoryOperationService::class);
        $operations->markReady($operation->refresh(), $context->as('operations'));
        $operations->complete($operation->refresh(), $context->as('operations'));

        app(SalesProcurementRequirementService::class)->refreshFromPurchaseOrder($purchaseOrder->refresh());
    }
}
