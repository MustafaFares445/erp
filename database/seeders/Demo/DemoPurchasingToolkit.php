<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\SupplierConfirmationStatus;
use App\Models\Bill;
use App\Models\InventoryOperation;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Scene helpers for the purchasing month: every step pins the clock, signs the right actor in
 * and calls the real domain service. Orders are addressed by their stable demo key ("PO-01"),
 * stored in the order notes as "[DEMO] PO-01 - ...".
 */
final readonly class DemoPurchasingToolkit
{
    public const string TaxRate = '0.05';

    private DemoContext $context;

    private DemoInventory $inventory;

    public function __construct()
    {
        $this->context = DemoContext::make();
        $this->inventory = DemoInventory::make();
    }

    public function step(string $when, string $actor): User
    {
        $this->context->at($when);

        return $this->context->as($actor);
    }

    public function supplier(int $number): Supplier
    {
        return $this->inventory->supplier(sprintf('DEMO-SUP-%03d', $number));
    }

    /** "P007-L" => variant DEMO-P007-L */
    public function variant(string $key): ProductVariant
    {
        [$product, $suffix] = explode('-', $key, 2);

        return $this->inventory->variant($product, $suffix);
    }

    public function order(string $key): PurchaseOrder
    {
        return PurchaseOrder::query()->where('notes', 'like', "[DEMO] {$key} -%")->firstOrFail();
    }

    /**
     * Draft purchase order as the purchasing officer.
     *
     * @param  list<array{0: string, 1: int|string, 2?: string}>  $lines  [variant key, quantity, optional explicit unit cost]
     */
    public function draft(string $key, int $supplier, array $lines, string $orderedAt, ?string $expectedAt, string $note): PurchaseOrder
    {
        $officer = $this->context->actor('purchasing_officer');
        $this->context->as('purchasing_officer');

        $payload = [];

        foreach ($lines as $line) {
            $variant = $this->variant($line[0]);
            $row = [
                'product_variant_id' => DemoContext::keyOf($variant),
                'unit_id' => $variant->unit_id,
                'quantity_ordered' => (string) $line[1],
            ];

            if (isset($line[2])) {
                $row['unit_cost'] = $line[2];
            }

            $payload[] = $row;
        }

        return app(PurchaseOrderService::class)->createDraftWithLines($officer, [
            'supplier_id' => DemoContext::keyOf($this->supplier($supplier)),
            'currency_code' => 'AED',
            'ordered_at' => $orderedAt,
            'expected_at' => $expectedAt,
            'notes' => "[DEMO] {$key} - {$note}",
        ], $payload);
    }

    public function submit(string $key): PurchaseOrder
    {
        return app(PurchaseOrderApprovalService::class)->submit($this->context->actor('purchasing_officer'), $this->order($key));
    }

    public function approve(string $key): PurchaseOrder
    {
        return app(PurchaseOrderApprovalService::class)->approve($this->context->actor('purchasing_manager'), $this->order($key));
    }

    public function reject(string $key, string $reason): PurchaseOrder
    {
        return app(PurchaseOrderApprovalService::class)->reject($this->context->actor('purchasing_manager'), $this->order($key), $reason);
    }

    public function send(string $key): PurchaseOrder
    {
        return app(PurchaseOrderApprovalService::class)->send($this->context->actor('purchasing_manager'), $this->order($key));
    }

    public function cancel(string $key, string $reason): PurchaseOrder
    {
        return app(PurchaseOrderApprovalService::class)->cancel($this->context->actor('purchasing_manager'), $this->order($key), $reason);
    }

    /**
     * Answer the latest pending confirmation of an order.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $quantities  per item index: [confirmed, backordered]; null = confirm everything
     */
    public function answer(string $key, SupplierConfirmationStatus $outcome, ?string $promisedAt, string $note, ?array $quantities = null): SupplierConfirmation
    {
        $officer = $this->context->actor('purchasing_officer');
        $confirmation = $this->order($key)->confirmations()->where('confirmation_status', 'pending')->latest('id')->firstOrFail();
        $items = [];

        if ($outcome !== SupplierConfirmationStatus::Rejected) {
            foreach ($confirmation->items->values() as $index => $item) {
                [$confirmed, $backordered] = $quantities[$index] ?? [(string) $item->requested_base_quantity, '0'];
                $items[] = [
                    'id' => DemoContext::keyOf($item),
                    'confirmed_base_quantity' => $confirmed,
                    'backordered_base_quantity' => $backordered,
                    'promised_at' => $promisedAt,
                ];
            }
        }

        return app(SupplierConfirmationService::class)->respond(
            $officer,
            $confirmation,
            $outcome,
            $promisedAt === null ? null : CarbonImmutable::parse($promisedAt),
            $note,
            $items,
        );
    }

    public function followUp(string $key, string $note): SupplierConfirmation
    {
        return app(SupplierConfirmationService::class)->recordPurchaseOrder($this->context->actor('purchasing_officer'), $this->order($key), $note);
    }

    public function allocateAll(string $key, string $warehouseCode): void
    {
        app(PurchaseInboundService::class)->allocateAllTo(
            $this->context->actor('operations'),
            $this->order($key),
            $this->inventory->warehouse($warehouseCode),
        );
    }

    public function allocatePartial(string $key, string $warehouseCode, string $baseQuantity): void
    {
        $order = $this->order($key);
        $line = $this->inbound($order)->lines()->sole();

        app(PurchaseInboundService::class)->allocate(
            $this->context->actor('operations'),
            $line,
            $this->inventory->warehouse($warehouseCode),
            $baseQuantity,
        );
    }

    /**
     * Draft receipt for everything still allocated (or a capped quantity on the single line),
     * identity fix-ups, ready, done. Returns the completed operation.
     */
    public function receive(string $key, string $tag, ?string $limit = null): InventoryOperation
    {
        $operation = $this->draftReceipt($key, $limit);
        $this->inventory->completeReceiptIdentity($operation->refresh(), $tag);

        $actor = $this->context->actor('operations');
        $operations = app(InventoryOperationService::class);
        $operations->markReady($operation->refresh(), $actor);
        $operations->complete($operation->refresh(), $actor);

        return $operation->refresh();
    }

    public function draftReceipt(string $key, ?string $limit = null): InventoryOperation
    {
        $actor = $this->context->actor('operations');
        $receiving = app(PurchaseOrderReceivingService::class);
        $order = $this->order($key)->load('purchaseInbound.lines.allocations');
        $lines = [];

        foreach ($this->inbound($order)->lines as $inboundLine) {
            foreach ($inboundLine->allocations as $allocation) {
                $available = $receiving->availableBaseQuantityForAllocation($allocation);

                if ((float) $available <= 0) {
                    continue;
                }

                $lines[] = [
                    'purchase_inbound_allocation_id' => DemoContext::keyOf($allocation),
                    'quantity' => $limit !== null && $lines === [] ? $limit : $available,
                ];
            }
        }

        if ($lines === []) {
            throw new RuntimeException("Nothing allocated for receipt on {$key}.");
        }

        return $receiving->initiate($actor, $order, $lines);
    }

    public function remainderDraftReceipt(string $key): InventoryOperation
    {
        $allocation = $this->inbound($this->order($key))->lines()->sole()->allocations()->sole();

        return app(PurchaseOrderReceivingService::class)->ensureDraftReceiptForAllocation($this->context->actor('operations'), $allocation->fresh() ?? $allocation);
    }

    public function allocationOf(string $key): PurchaseInboundAllocation
    {
        return $this->inbound($this->order($key))->lines()->sole()->allocations()->sole();
    }

    /**
     * Turn the PO-generated draft bill into the supplier's real invoice: reference, invoice date,
     * payment term and 5% input VAT, keeping the header totals equal to the line sums.
     */
    public function prepareBill(string $key, string $reference, string $billDate, string $term): Bill
    {
        $this->context->as('accountant');
        $bill = Bill::query()->where('purchase_order_id', DemoContext::keyOf($this->order($key)))->sole();

        foreach ($bill->lines as $line) {
            $line->update(['tax_amount' => number_format(round((float) $line->line_total * (float) self::TaxRate, 2), 2, '.', '')]);
        }

        $subtotal = round((float) $bill->lines()->sum('line_total'), 2);
        $tax = round((float) $bill->lines()->sum('tax_amount'), 2);
        $total = number_format($subtotal + $tax, 2, '.', '');

        $bill->update([
            'supplier_reference' => $reference,
            'bill_date' => $billDate,
            'payment_term_id' => DemoContext::keyOf($this->term($term)),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'tax_total' => number_format($tax, 2, '.', ''),
            'total_amount' => $total,
            'grand_total' => $total,
        ]);

        return $bill->refresh();
    }

    public function approveBill(Bill $bill): Bill
    {
        return app(AccountingDocumentService::class)->approveBill($this->context->actor('chief_accountant'), $bill->refresh());
    }

    public function billOf(string $key): Bill
    {
        return Bill::query()->where('purchase_order_id', DemoContext::keyOf($this->order($key)))->sole();
    }

    /**
     * Record and settle one supplier payment against one or more bills.
     *
     * @param  list<array{0: Bill, 1: string}>  $allocations
     */
    public function pay(int $supplier, string $method, string $amount, string $date, string $reference, array $allocations): SupplierPayment
    {
        $accountant = $this->context->actor('accountant');
        $documents = app(AccountingDocumentService::class);

        $payment = $documents->recordSupplierPayment($accountant, [
            'supplier_id' => DemoContext::keyOf($this->supplier($supplier)),
            'payment_method_id' => DemoContext::keyOf(PaymentMethod::query()->where('name', $method)->firstOrFail()),
            'amount' => $amount,
            'payment_date' => $date,
            'reference' => $reference,
        ]);

        return $documents->paySupplierPayment($accountant, $payment, array_map(
            static fn (array $allocation): array => ['bill_id' => DemoContext::keyOf($allocation[0]), 'amount' => $allocation[1]],
            $allocations,
        ));
    }

    private function inbound(PurchaseOrder $order): PurchaseInbound
    {
        return $order->purchaseInbound ?? throw new RuntimeException(sprintf('Purchase order [%d] has no purchase inbound.', DemoContext::keyOf($order)));
    }

    public function term(string $name): PaymentTerm
    {
        return PaymentTerm::query()->where('name', $name)->firstOrFail();
    }
}
