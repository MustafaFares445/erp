<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\OperationStage;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Models\SupplierProductReference;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Annual historical purchasing ledger in the SAME local development company.
 *
 * 156 real-service-generated purchase commitments, across twelve reporting
 * months; weighted draft, approved, sent and genuinely received outcomes.
 * Every posted receipt is tied to the exact supplier PO/inbound allocation.
 */
final class UnifiedPurchasingHistorySeeder extends DemoSeeder
{
    private const int Orders = 156;

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()))->startOfDay();
        $refs = SupplierProductReference::query()
            ->where('is_active', true)
            ->whereHas('supplier', fn ($q) => $q->where('is_active', true)->where('requires_confirmation', false))
            ->whereHas('productVariant', fn ($q) => $q->where('sku', 'like', 'DEMO-REAL-%'))
            ->with(['supplier', 'productVariant'])->orderBy('id')->get();
        $warehouse = Warehouse::query()->where('code', 'DEMO-WH-004')->firstOrFail();
        if ($refs->isEmpty()) {
            throw new LogicException('Unified product supplier references are missing.');
        }

        $orders = app(PurchaseOrderService::class);
        $approval = app(PurchaseOrderApprovalService::class);
        $inbounds = app(PurchaseInboundService::class);
        $receiving = app(PurchaseOrderReceivingService::class);
        $inventory = app(InventoryOperationService::class);
        $identities = DemoInventory::make();
        $buyer = $context->actor('purchasing_officer');
        $manager = $context->actor('purchasing_manager');
        $operator = $context->actor('operations');

        foreach (range(1, self::Orders) as $n) {
            $reference = $refs[($n * 11) % $refs->count()]
                ?? throw new LogicException('Missing supplier reference for purchase history.');
            $variant = $reference->productVariant;
            $supplier = $reference->supplier;
            if ($variant === null || $supplier === null) {
                throw new LogicException("Missing supplier or product variant for historical PO {$n}.");
            }

            $key = sprintf('[DEMO-HISTORY] Procurement %04d', $n);
            $monthBack = 11 - (($n - 1) % 12);
            $when = $anchor->copy()->subMonths($monthBack)->startOfMonth()
                ->addDays(1 + (($n * 5) % 23))->setTime(9 + $n % 7, 0);
            if ($when->greaterThan($anchor)) {
                $when = $anchor->copy()->subDays($n % 8 + 1);
            }

            $context->at($when->toDateTimeString());
            $context->as('purchasing_officer');
            $order = PurchaseOrder::query()->where('notes', $key)->first();

            if (! $order instanceof PurchaseOrder) {
                $quantity = $variant->productType() === ProductType::Machine
                    ? 1 + ($n % 2)
                    : 4 + ($n % 12) * 2;
                $order = $orders->createDraftWithLines($buyer, [
                    'supplier_id' => DemoContext::keyOf($supplier),
                    'currency_code' => 'AED',
                    'ordered_at' => $when->toDateString(),
                    'expected_at' => $when->copy()->addDays(DemoContext::intOf($reference->lead_time_days) + 3)->toDateString(),
                    'notes' => $key,
                ], [[
                    'product_variant_id' => DemoContext::keyOf($variant),
                    'unit_id' => (int) $variant->unit_id,
                    'quantity_ordered' => (string) $quantity,
                ]]);
            }

            // 1/13 remains a draft; the other branches exercise real approval.
            $branch = $n % 13;
            if ($branch === 0) {
                continue;
            }

            if ($order->status === PurchaseOrderStatus::Draft) {
                $context->at($when->copy()->addHours(2)->toDateTimeString());
                $order = $approval->submit($buyer, $order);
            }
            if ($order->status === PurchaseOrderStatus::PendingApproval) {
                $context->at($when->copy()->addHours(4)->toDateTimeString());
                $order = $approval->approve($manager, $order);
            }

            // Some commitments are approved but deliberately not transmitted.
            if ($branch === 1) {
                continue;
            }

            if ($order->status === PurchaseOrderStatus::Accepted && $order->sent_at === null) {
                $context->at($when->copy()->addDay()->toDateTimeString());
                $order = $approval->send($manager, $order);
            }

            // 2/13 are sent with inbound allocations still awaiting goods.
            if ($branch <= 3) {
                continue;
            }

            if (InventoryOperation::query()
                ->where('source_document_type', PurchaseOrder::class)
                ->where('source_document_id', $order->getKey())
                ->where('stage', OperationStage::Done->value)->exists()) {
                continue;
            }

            $context->at($when->copy()->addDays(DemoContext::intOf($reference->lead_time_days) + 4)->toDateTimeString());
            $context->as('operations');
            $inbounds->allocateAllTo($operator, $order->refresh(), $warehouse);
            $operation = $receiving->initiate($operator, $order->refresh());
            $identities->completeReceiptIdentity($operation->refresh(), sprintf('H%04d', $n));
            $inventory->markReady($operation->refresh(), $operator);
            $inventory->complete($operation->refresh(), $operator);
        }

        $this->note('Added 156 synthetic purchase orders across a year and linked receipts through Inventory.');
    }
}
