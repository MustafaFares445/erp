<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Inventory\DamageDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\StockCondition;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\SupplierConfirmation;
use App\Models\SupplierPayment;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Inventory\InventoryConditionChangeService;
use App\Services\Inventory\InventoryReturnService;
use Closure;
use Illuminate\Support\Facades\Artisan;

/**
 * Purchasing and payables for 2026-09-04 .. 2026-10-03, driven only through the domain services.
 *
 * The month is a chronological list of scenes ([moment, actor, closure]); the runner pins the clock
 * and signs the actor in before each one. Order keys (PO-01 ...) are the stories in the demo brief,
 * AUG-1/AUG-2 are two small orders placed in the previous reporting window.
 */
final class DemoPurchasingMonthSeeder extends DemoSeeder
{
    /**
     * key => [supplier number, [variant key, qty, optional unit cost][], ordered_at, expected_at, label]
     *
     * @var array<string, array{0: int, 1: list<array{0: string, 1: int|string, 2?: string}>, 2: string, 3: string, 4: string}>
     */
    private const array Orders = [
        'AUG-1' => [1, [['P007-S', 100], ['P013-1L', 50]], '2026-08-28', '2026-09-05', 'Gloves and irrigation top-up placed before the month'],
        'AUG-2' => [6, [['P009-STD', 30], ['P010-A2', 20]], '2026-08-31', '2026-09-11', 'Cement and composite replenishment'],
        'PO-01' => [3, [['P004-40X10', 20], ['P004-40X12', 20]], '2026-09-05', '2026-09-14', 'Implant fixture replenishment'],
        'PO-02' => [4, [['P019-MOTOR', 1]], '2026-10-02', '2026-10-16', 'Surgical drill motor unit'],
        'PO-03' => [2, [['P003-1L', 9]], '2026-10-01', '2026-10-12', 'Temporary crown resin 1 L'],
        'PO-04' => [5, [['P018-UPPER', 40], ['P020-PRO', 10]], '2026-09-30', '2026-10-08', 'Impression trays and professional maintenance kits'],
        'PO-05' => [3, [['P005-35MM', 100]], '2026-09-21', '2026-10-05', 'Healing abutments'],
        'PO-06' => [4, [['P012-25MM', 70]], '2026-09-16', '2026-10-07', 'Endodontic file sets'],
        'PO-07' => [7, [['P006-PUTTY', 60], ['P006-LIGHT', 40]], '2026-09-10', '2026-09-22', 'Impression putty and light body'],
        'PO-08' => [6, [['P010-A3', 46]], '2026-09-17', '2026-09-24', 'Composite shade A3'],
        'PO-09' => [7, [['P013-500ML', 100], ['P007-S', 100], ['P008-90X230', 100]], '2026-09-08', '2026-09-15', 'Consumables mixed order'],
        'PO-10' => [2, [['P002-5L', 15], ['P002-1L', 16]], '2026-09-19', '2026-09-28', 'Model resin replenishment'],
        'PO-11' => [5, [['P018-LOWER', 40], ['P020-BASIC', 20]], '2026-09-26', '2026-10-02', 'Impression trays and maintenance kits'],
        'PO-12' => [2, [['P001-500ML', 20]], '2026-09-09', '2026-09-16', 'Surgical guide resin'],
        'PO-13' => [1, [['P007-L', 150], ['P008-135X280', 180], ['P013-500ML', 100]], '2026-09-12', '2026-09-18', 'Large replenishment for the Main Clinic Store'],
        'PO-14' => [6, [['P011-5ML', 20]], '2026-09-26', '2026-10-01', 'Bonding agent for Cold Chain Storage'],
        'PO-16' => [2, [['P017-LONG', 10]], '2026-09-29', '2026-10-06', 'Scan bodies, first tranche'],
        'PO-15' => [1, [['P007-M', 150], ['P008-90X230', 200]], '2026-10-02', '2026-10-14', 'Gloves and pouches, quote above budget'],
    ];

    private DemoPurchasingToolkit $t;

    private DemoPurchasingSourcing $s;

    private int $sceneSequence = 0;

    protected function seed(DemoContext $context): void
    {
        if (PurchaseOrder::query()->where('notes', 'like', '[DEMO] %')->exists()) {
            $this->note('Purchasing demo month already present - skipped.');

            return;
        }

        $this->t = new DemoPurchasingToolkit;
        $this->s = new DemoPurchasingSourcing($this->t, $context);

        $scenes = [
            ...$this->sourcingScenes(),
            ...$this->orderScenes(),
            ...$this->receivingScenes(),
            ...$this->billingScenes(),
            ...$this->needScenes(),
        ];

        usort($scenes, static fn (array $a, array $b): int => [$a[0], $a[3]] <=> [$b[0], $b[3]]);

        foreach ($scenes as [$moment, $actor, $scene]) {
            $this->t->step($moment, $actor);
            $scene();
        }

        $context->at('2026-10-03 09:00');
        Artisan::call('inventory:alerts:reconcile');
    }

    /**
     * Scene tuples are [moment, actor, closure, sequence]; the sequence keeps same-minute scenes in source order.
     *
     * @return array{0: string, 1: string, 2: Closure, 3: int}
     */
    private function scene(string $moment, string $actor, Closure $scene): array
    {
        return [$moment.':00', $actor, $scene, ++$this->sceneSequence];
    }

    /**
     * Create a draft and submit it (auto-approves at or below the AED 5,000 threshold).
     *
     * @return array{0: string, 1: string, 2: Closure, 3: int}
     */
    private function place(string $key, string $moment): array
    {
        [$supplier, $lines, $orderedAt, $expectedAt, $label] = self::Orders[$key];

        return $this->scene($moment, 'purchasing_officer', function () use ($key, $supplier, $lines, $orderedAt, $expectedAt, $label): void {
            $this->t->draft($key, $supplier, $lines, $orderedAt, $expectedAt, $label);
            $this->t->submit($key);
        });
    }

    /** @return list<array{0: string, 1: string, 2: Closure, 3: int}> */
    private function orderScenes(): array
    {
        $t = $this->t;

        return [
            // Previous-window orders, entered on the first morning of the demo.
            $this->place('AUG-1', '2026-09-04 09:00'),
            $this->scene('2026-09-04 09:30', 'purchasing_manager', fn (): PurchaseOrder => $t->send('AUG-1')),
            $this->place('AUG-2', '2026-09-04 10:00'),
            $this->scene('2026-09-04 10:30', 'purchasing_manager', fn (): PurchaseOrder => $t->send('AUG-2')),
            $this->scene('2026-09-04 11:00', 'operations', function () use ($t): void {
                $t->allocateAll('AUG-1', 'WH-MAIN');
                $t->allocateAll('AUG-2', 'WH-MAIN');
            }),

            // PO-01: full lifecycle, supplier requires confirmation.
            $this->place('PO-01', '2026-09-05 10:00'),
            $this->scene('2026-09-06 09:00', 'purchasing_manager', fn (): PurchaseOrder => $t->approve('PO-01')),
            $this->scene('2026-09-07 10:00', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-01')),
            $this->scene('2026-09-08 09:00', 'purchasing_officer', fn (): SupplierConfirmation => $t->answer('PO-01', SupplierConfirmationStatus::Confirmed, '2026-09-14', 'Supplier confirmed full quantity for Monday 14 September.')),
            $this->scene('2026-09-08 10:00', 'operations', fn () => $t->allocateAll('PO-01', 'WH-MAIN')),

            // PO-09: mixed consumables, billed and unpaid.
            $this->place('PO-09', '2026-09-08 11:00'),
            $this->scene('2026-09-08 15:00', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-09')),
            $this->scene('2026-09-09 11:00', 'operations', fn () => $t->allocateAll('PO-09', 'WH-MAIN')),

            // PO-12: cancelled the day after it was sent.
            $this->place('PO-12', '2026-09-09 09:30'),
            $this->scene('2026-09-09 10:00', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-12')),
            $this->scene('2026-09-10 10:00', 'purchasing_manager', fn (): PurchaseOrder => $t->cancel('PO-12', 'Duplicate of the RFQ in progress; supplier asked to hold the goods.')),

            // PO-07: becomes the overdue delivery.
            $this->place('PO-07', '2026-09-10 09:00'),
            $this->scene('2026-09-11 09:00', 'purchasing_manager', function () use ($t): void {
                $t->approve('PO-07');
                $t->send('PO-07');
            }),
            $this->scene('2026-09-12 09:00', 'operations', fn () => $t->allocateAll('PO-07', 'WH-MAIN')),

            // PO-13: large replenishment for the Main Clinic Store.
            $this->place('PO-13', '2026-09-12 11:00'),
            $this->scene('2026-09-13 09:00', 'purchasing_manager', function () use ($t): void {
                $t->approve('PO-13');
                $t->send('PO-13');
            }),
            $this->scene('2026-09-14 15:00', 'operations', fn () => $t->allocateAll('PO-13', 'WH-MAIN')),

            // PO-06 and PO-08.
            $this->place('PO-06', '2026-09-16 11:00'),
            $this->scene('2026-09-17 09:00', 'purchasing_manager', function () use ($t): void {
                $t->approve('PO-06');
                $t->send('PO-06');
            }),
            $this->place('PO-08', '2026-09-17 10:00'),
            $this->scene('2026-09-17 10:30', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-08')),
            $this->scene('2026-09-18 10:00', 'operations', function () use ($t): void {
                $t->allocateAll('PO-06', 'WH-MAIN');
                $t->allocateAll('PO-08', 'WH-MAIN');
            }),

            // PO-10.
            $this->place('PO-10', '2026-09-19 11:00'),
            $this->scene('2026-09-20 09:00', 'purchasing_manager', function () use ($t): void {
                $t->approve('PO-10');
                $t->send('PO-10');
            }),
            $this->scene('2026-09-21 10:00', 'operations', fn () => $t->allocateAll('PO-10', 'WH-MAIN')),

            // PO-05: supplier confirms 70 of 100 and backorders the rest.
            $this->place('PO-05', '2026-09-21 11:00'),
            $this->scene('2026-09-22 09:00', 'purchasing_manager', function () use ($t): void {
                $t->approve('PO-05');
                $t->send('PO-05');
            }),
            $this->scene('2026-09-23 09:00', 'purchasing_officer', function () use ($t): void {
                $t->answer('PO-05', SupplierConfirmationStatus::Partial, '2026-10-09', 'Only 70 units in stock; 30 backordered, delayed to 9 October.', [0 => ['70', '30']]);
                $t->followUp('PO-05', 'Follow up the 30 backordered healing abutments.');
            }),
            $this->scene('2026-09-23 10:00', 'operations', fn () => $t->allocatePartial('PO-05', 'WH-MAIN', '70')),

            // PO-14: cold chain order.
            $this->place('PO-14', '2026-09-26 09:00'),
            $this->scene('2026-09-26 09:30', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-14')),
            $this->scene('2026-09-26 10:00', 'operations', fn () => $t->allocateAll('PO-14', 'WH-COLD')),

            // PO-11: supplier will reject the order.
            $this->place('PO-11', '2026-09-26 11:00'),
            $this->scene('2026-09-28 09:00', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-11')),
            $this->scene('2026-10-01 09:30', 'purchasing_officer', fn (): SupplierConfirmation => $t->answer('PO-11', SupplierConfirmationStatus::Rejected, null, 'Supplier cannot deliver: maintenance kits discontinued this quarter.')),

            // PO-16: scan bodies on their way, covers part of the open need.
            $this->place('PO-16', '2026-09-29 10:00'),
            $this->scene('2026-09-29 10:30', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-16')),
            $this->scene('2026-09-29 11:00', 'operations', fn () => $t->allocateAll('PO-16', 'WH-MAIN')),

            // PO-04: waiting for the supplier.
            $this->place('PO-04', '2026-09-30 09:00'),
            $this->scene('2026-10-01 09:00', 'purchasing_manager', fn (): PurchaseOrder => $t->send('PO-04')),

            // PO-03 approved but not sent; PO-02 pending approval; PO-15 returned for revision.
            $this->place('PO-03', '2026-10-01 11:00'),
            $this->scene('2026-10-01 15:00', 'purchasing_manager', fn (): PurchaseOrder => $t->approve('PO-03')),
            $this->place('PO-02', '2026-10-02 10:00'),
            $this->place('PO-15', '2026-10-02 10:30'),
            $this->scene('2026-10-02 11:30', 'purchasing_manager', fn (): PurchaseOrder => $t->reject('PO-15', 'Quoted price is above budget; renegotiate with the supplier before resubmitting.')),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: Closure, 3: int}> */
    private function receivingScenes(): array
    {
        $t = $this->t;

        return [
            $this->scene('2026-09-06 11:00', 'operations', fn (): InventoryOperation => $t->receive('AUG-1', 'AUG1')),
            $this->scene('2026-09-12 10:00', 'operations', fn (): InventoryOperation => $t->receive('AUG-2', 'AUG2')),
            $this->scene('2026-09-14 09:00', 'operations', fn (): InventoryOperation => $t->receive('PO-01', 'PO01')),
            $this->scene('2026-09-15 11:00', 'operations', fn (): InventoryOperation => $t->receive('PO-09', 'PO09')),
            $this->scene('2026-09-18 09:00', 'operations', fn (): InventoryOperation => $t->receive('PO-13', 'PO13')),
            $this->scene('2026-09-19 09:00', 'operations', fn () => $this->returnDamagedStock()),
            $this->scene('2026-09-24 09:00', 'operations', fn (): InventoryOperation => $t->receive('PO-08', 'PO08')),
            $this->scene('2026-09-25 09:00', 'operations', function () use ($t): void {
                $t->receive('PO-06', 'PO06', '50');
                $t->remainderDraftReceipt('PO-06');
            }),
            $this->scene('2026-09-28 10:00', 'operations', fn (): InventoryOperation => $t->receive('PO-10', 'PO10')),
            $this->scene('2026-10-01 10:00', 'operations', fn (): InventoryOperation => $t->receive('PO-14', 'PO14')),
        ];
    }

    /** Damaged-in-transit irrigation solution from PO-13 goes back to the supplier (stock only, no credit note exists). */
    private function returnDamagedStock(): void
    {
        $actor = DemoContext::make()->actor('operations');
        $variant = $this->t->variant('P013-500ML');
        $warehouse = DemoInventory::make()->warehouse('WH-MAIN');
        $order = $this->t->order('PO-13');

        $receipt = InventoryOperation::query()
            ->where('source_document_type', PurchaseOrder::class)
            ->where('source_document_id', $order->getKey())
            ->firstOrFail();
        $receiptLine = InventoryOperationLine::query()
            ->where('inventory_operation_id', $receipt->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->firstOrFail();

        $conditions = app(InventoryConditionChangeService::class);
        $damage = $conditions->draftDamage(new DamageDraftData(
            DemoContext::keyOf($variant),
            DemoContext::keyOf($warehouse),
            $receiptLine->inventory_lot_id,
            null,
            '6.000000',
            ConditionChangeReason::DamagedInTransit,
            'Six bottles leaked in transit on delivery PO-13.',
        ), $actor);
        $conditions->post($damage, $actor);

        $returns = app(InventoryReturnService::class);
        $return = $returns->createSupplierReturn(
            $actor,
            $this->t->supplier(1),
            $warehouse,
            $receipt,
            DemoContext::keyOf($order),
            'Damaged in transit',
            '[DEMO] Leaking irrigation bottles returned to MedSupply Gulf.',
        );
        $returns->addSupplierLine($return, $variant, (int) $variant->unit_id, '6.000000', StockCondition::Damaged, $receiptLine->inventory_lot_id, null, $receiptLine);
        $returns->markReady($return, $actor);
        $returns->post($return->refresh(), $actor);
    }

    /** @return list<array{0: string, 1: string, 2: Closure, 3: int}> */
    private function billingScenes(): array
    {
        $t = $this->t;
        $documents = fn (): AccountingDocumentService => app(AccountingDocumentService::class);
        $standalone = function (int $supplier, string $reference, string $date, string $description, string $net, string $tax, string $term) use ($documents, $t): void {
            $accountant = DemoContext::make()->actor('accountant');
            $total = number_format((float) $net + (float) $tax, 2, '.', '');
            $account = ChartAccount::query()->where('code', '5900')->firstOrFail();

            $documents()->recordBill($accountant, [
                'supplier_id' => $t->supplier($supplier)->getKey(),
                'supplier_reference' => $reference,
                'bill_date' => $date,
                'payment_term_id' => $t->term($term)->getKey(),
                'description' => $description,
                'subtotal' => $net,
                'tax_total' => $tax,
                'total_amount' => $total,
            ], [[
                'chart_account_id' => $account->getKey(),
                'description' => $description,
                'quantity' => '1.000',
                'unit_price' => $net,
                'tax_amount' => $tax,
                'line_total' => $net,
                'sort_order' => 1,
            ]]);
        };
        $approveStandalone = fn (string $reference): Bill => $documents()->approveBill(
            DemoContext::make()->actor('chief_accountant'),
            Bill::query()->where('supplier_reference', $reference)->firstOrFail(),
        );
        $standaloneBill = fn (string $reference): Bill => Bill::query()->where('supplier_reference', $reference)->firstOrFail();

        return [
            // AUG-1: small order, billed and paid in full by bank transfer.
            $this->scene('2026-09-07 14:00', 'accountant', fn (): Bill => $t->prepareBill('AUG-1', 'MSG-INV-26-0388', '2026-09-07', 'Net 15')),
            $this->scene('2026-09-08 09:30', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('AUG-1'))),
            $this->scene('2026-09-22 10:00', 'accountant', fn (): SupplierPayment => $t->pay(1, 'Exchange House Remittance', '3045.00', '2026-09-22', 'REMIT-87654', [[$t->billOf('AUG-1'), '3045.00']])),

            // Standalone service bill from HealthLine: approved and now overdue.
            $this->scene('2026-09-08 10:30', 'accountant', fn () => $standalone(5, 'HL-SVC-26-114', '2026-09-08', 'Quarterly equipment calibration contract', '8000.00', '400.00', 'Net 15')),
            $this->scene('2026-09-09 09:00', 'chief_accountant', fn (): Bill => $approveStandalone('HL-SVC-26-114')),

            // PO-01: billed on 15 September, partly paid.
            $this->scene('2026-09-15 09:00', 'accountant', fn (): Bill => $t->prepareBill('PO-01', 'SMT-INV-26-2207', '2026-09-15', 'Net 30')),
            $this->scene('2026-09-15 15:00', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('PO-01'))),
            $this->scene('2026-09-28 14:00', 'accountant', fn (): SupplierPayment => $t->pay(3, 'Operating Bank Transfer', '5000.00', '2026-09-28', 'TRF-26-0928-01', [[$t->billOf('PO-01'), '5000.00']])),

            // AUG-2: approved, partly paid by cheque, balance overdue.
            $this->scene('2026-09-14 14:00', 'accountant', fn (): Bill => $t->prepareBill('AUG-2', 'PRD-INV-8841', '2026-09-12', 'Net 15')),
            $this->scene('2026-09-15 10:00', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('AUG-2'))),
            $this->scene('2026-09-25 10:00', 'accountant', fn (): SupplierPayment => $t->pay(6, 'Cheque Deposit', '2000.00', '2026-09-25', 'CHQ-004517', [[$t->billOf('AUG-2'), '2000.00']])),

            // PO-09: approved, unpaid.
            $this->scene('2026-09-16 09:00', 'accountant', fn (): Bill => $t->prepareBill('PO-09', 'GCS-INV-26-0731', '2026-09-15', 'Net 30')),
            $this->scene('2026-09-16 10:00', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('PO-09'))),

            // Standalone freight and handling bill from Precision Medical: paid from the cash desk.
            $this->scene('2026-09-17 14:00', 'accountant', fn () => $standalone(4, 'PMS-FRT-26-077', '2026-09-17', 'Instrument servicing and freight handling', '3095.24', '154.76', 'Net 15')),
            $this->scene('2026-09-18 11:00', 'chief_accountant', fn (): Bill => $approveStandalone('PMS-FRT-26-077')),
            $this->scene('2026-09-20 10:00', 'accountant', fn (): SupplierPayment => $t->pay(4, 'Cash Desk', '3250.00', '2026-09-20', 'CASH-VOUCHER-0920', [[$standaloneBill('PMS-FRT-26-077'), '3250.00']])),

            // PO-13: billed and settled in two instalments (bank, then cheque for the balance).
            $this->scene('2026-09-19 10:00', 'accountant', fn (): Bill => $t->prepareBill('PO-13', 'MSG-INV-26-0412', '2026-09-18', 'Net 30')),
            $this->scene('2026-09-19 10:30', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('PO-13'))),
            $this->scene('2026-09-26 14:00', 'accountant', fn (): SupplierPayment => $t->pay(1, 'Operating Bank Transfer', '3000.00', '2026-09-26', 'TRF-26-0926-01', [[$t->billOf('PO-13'), '3000.00']])),
            $this->scene('2026-10-02 14:00', 'accountant', fn (): SupplierPayment => $t->pay(1, 'Cheque Deposit', '4140.00', '2026-10-02', 'CHQ-004533', [[$t->billOf('PO-13'), '4140.00']])),

            // PO-10: AED 12,600 with AED 5,000 paid.
            $this->scene('2026-09-28 11:00', 'accountant', fn (): Bill => $t->prepareBill('PO-10', 'DTD-INV-26-3390', '2026-09-28', 'Net 30')),
            $this->scene('2026-09-29 09:00', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('PO-10'))),
            $this->scene('2026-10-01 10:30', 'accountant', fn (): SupplierPayment => $t->pay(2, 'Operating Bank Transfer', '5000.00', '2026-10-01', 'TRF-26-1001-01', [[$t->billOf('PO-10'), '5000.00']])),

            // PO-14: billed the day after receipt and settled by bank transfer.
            $this->scene('2026-10-02 09:00', 'accountant', fn (): Bill => $t->prepareBill('PO-14', 'PRO-INV-26-0155', '2026-10-01', 'Net 15')),
            $this->scene('2026-10-02 09:30', 'chief_accountant', fn (): Bill => $t->approveBill($t->billOf('PO-14'))),
            $this->scene('2026-10-02 15:00', 'accountant', fn (): SupplierPayment => $t->pay(6, 'Operating Bank Transfer', '2310.00', '2026-10-02', 'TRF-26-1002-01', [[$t->billOf('PO-14'), '2310.00']])),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: Closure, 3: int}> */
    private function sourcingScenes(): array
    {
        $s = $this->s;

        return [
            // Framework agreements: active, active-but-not-started, expired later, draft, cancelled.
            $this->scene('2026-09-04 14:00', 'purchasing_manager', function () use ($s): void {
                $s->agreement('[DEMO] DentalTech resin framework 2026', 2, '2026-09-01', '2026-12-31', [['P001-500ML', '175.00', '10', 5], ['P001-1L', '310.00', '5', 5]]);
                $s->activateAgreement('[DEMO] DentalTech resin framework 2026');

                $s->agreement('[DEMO] ProDent bonding and cement framework 2027', 6, '2026-10-15', '2027-03-31', [['P009-STD', '80.00', '20', 7]]);
                $s->activateAgreement('[DEMO] ProDent bonding and cement framework 2027');

                $s->agreement('[DEMO] MedSupply tray price list (summer)', 1, '2026-08-01', '2026-09-20', [['P018-UPPER', '24.50', '20', 4]]);
                $s->activateAgreement('[DEMO] MedSupply tray price list (summer)');

                $s->agreement('[DEMO] Global Clinical consumables draft', 7, '2026-11-01', '2027-04-30', [['P013-500ML', '14.00', '50', 6]]);

                $s->agreement('[DEMO] Precision Medical instruments (withdrawn)', 4, '2026-09-01', '2026-12-31', [['P016-BASIC', '115.00', '10', 10]]);
                $s->cancelAgreement('[DEMO] Precision Medical instruments (withdrawn)');
            }),
            $this->scene('2026-09-21 09:00', 'purchasing_manager', fn () => $s->expireAgreement('[DEMO] MedSupply tray price list (summer)')),

            // R8 Expired.
            $this->scene('2026-09-08 16:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ irrigation solution (expired)', [['P013-500ML', 100]], [1, 7], '2026-09-30', '2026-09-15 18:00:00');
                $s->sendRfq('[DEMO] RFQ irrigation solution (expired)');
            }),
            $this->scene('2026-09-16 09:00', 'purchasing_manager', fn () => $s->expireRfq('[DEMO] RFQ irrigation solution (expired)')),

            // R7 Cancelled.
            $this->scene('2026-09-11 14:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ upper trays (cancelled)', [['P018-UPPER', 50]], [1, 5], '2026-09-30', '2026-09-20 18:00:00');
                $s->sendRfq('[DEMO] RFQ upper trays (cancelled)');
            }),
            $this->scene('2026-09-13 09:00', 'purchasing_manager', fn () => $s->cancelRfq('[DEMO] RFQ upper trays (cancelled)')),

            // R5 Awarded (PO still a draft).
            $this->scene('2026-09-14 11:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ irrigation 1 L (awarded)', [['P013-1L', 40]], [1, 7], '2026-10-20', '2026-09-21 18:00:00');
                $s->sendRfq('[DEMO] RFQ irrigation 1 L (awarded)');
            }),
            $this->scene('2026-09-15 09:15', 'purchasing_officer', function () use ($s): void {
                $s->quote('[DEMO] RFQ irrigation 1 L (awarded)', 1, ['21.50'], 5);
                $s->quote('[DEMO] RFQ irrigation 1 L (awarded)', 7, ['22.40'], 7);
            }),
            $this->scene('2026-09-17 11:00', 'purchasing_manager', fn () => $s->award('[DEMO] RFQ irrigation 1 L (awarded)', 1)),

            // R6 Closed.
            $this->scene('2026-09-18 14:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ gloves small and medium (closed)', [['P007-S', 100], ['P007-M', 60]], [1, 7], '2026-10-25', '2026-09-25 18:00:00');
                $s->sendRfq('[DEMO] RFQ gloves small and medium (closed)');
            }),
            $this->scene('2026-09-19 15:00', 'purchasing_officer', function () use ($s): void {
                $s->quote('[DEMO] RFQ gloves small and medium (closed)', 1, ['17.50', '17.50'], 4);
                $s->quote('[DEMO] RFQ gloves small and medium (closed)', 7, ['18.00', '18.00'], 6);
            }),
            $this->scene('2026-09-22 14:00', 'purchasing_manager', fn () => $s->award('[DEMO] RFQ gloves small and medium (closed)', 1)),
            $this->scene('2026-09-23 14:00', 'purchasing_manager', fn () => $s->closeRfq('[DEMO] RFQ gloves small and medium (closed)')),

            // R4 Evaluating (every supplier answered, nothing awarded).
            $this->scene('2026-09-24 11:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ impression materials (evaluating)', [['P006-LIGHT', 80], ['P006-HEAVY', 80]], [6, 7], '2026-10-30', '2026-10-08 18:00:00');
                $s->sendRfq('[DEMO] RFQ impression materials (evaluating)');
            }),
            $this->scene('2026-09-25 14:00', 'purchasing_officer', function () use ($s): void {
                $s->quote('[DEMO] RFQ impression materials (evaluating)', 6, ['44.00', '51.00'], 7);
                $s->quote('[DEMO] RFQ impression materials (evaluating)', 7, ['45.50', '52.40'], 5);
            }),

            // R3 Partially responded.
            $this->scene('2026-09-28 15:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ lower trays (partially responded)', [['P018-LOWER', 60]], [1, 5], '2026-10-31', '2026-10-09 18:00:00');
                $s->sendRfq('[DEMO] RFQ lower trays (partially responded)');
            }),
            $this->scene('2026-09-29 11:00', 'purchasing_officer', fn () => $s->quote('[DEMO] RFQ lower trays (partially responded)', 1, ['24.80'], 4)),

            // R2 Awaiting responses, R1 Draft.
            $this->scene('2026-10-01 14:00', 'purchasing_officer', function () use ($s): void {
                $s->rfq('[DEMO] RFQ sterilisation pouches (awaiting responses)', [['P008-90X230', 200]], [1, 7], '2026-11-05', '2026-10-09 18:00:00');
                $s->sendRfq('[DEMO] RFQ sterilisation pouches (awaiting responses)');
            }),
            $this->scene('2026-10-02 15:00', 'purchasing_officer', fn (): PurchaseRfq => $s->rfq('[DEMO] RFQ bur sets (draft)', [['P016-BASIC', 30]], [4], '2026-11-10', '2026-10-12 18:00:00')),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: Closure, 3: int}> */
    private function needScenes(): array
    {
        $s = $this->s;

        return [
            // Manual need: the cold store is reorganised and gets its own bonding agent policy (covered by PO-14).
            $this->scene('2026-09-20 11:00', 'operations', fn (): WarehouseReplenishmentPolicy => $s->policy('WH-COLD', 'P011-5ML', 16, 30)),
            // Main store no longer stocks bonding agent: that reorder need is withdrawn.
            $this->scene('2026-09-21 09:30', 'operations', fn () => $s->deactivatePolicy('WH-MAIN', 'P011-5ML')),
        ];
    }
}
