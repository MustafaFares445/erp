<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\DashboardRole;
use App\Enums\InventoryReturnDisposition;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\PaymentMethodType;
use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ProviderPaymentSettlementService;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Cross-module end-to-end scenarios: quotation -> order -> delivery -> invoice ->
 * payment -> tax recognition -> credit note / return. Every step goes through the
 * real service that production uses; the only direct writes are the fixtures
 * (stock, lot, the delivery operation that fulfils the order).
 */
beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->sole()->getKey(),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->sole()->getKey(),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->sole()->getKey(),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->sole()->getKey(),
        'customer_deposits_account_id' => ChartAccount::query()->where('code', '2400')->sole()->getKey(),
        'bad_debt_expense_account_id' => ChartAccount::query()->where('code', '6800')->sole()->getKey(),
    ])->save();

    FiscalPeriod::factory()->create();

    $this->actor = User::factory()->admin()->create();
    $this->actor->assignRole(DashboardRole::SystemAdmin->value);

    $this->customer = CustomerProfile::factory()->create();

    $this->paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => ChartAccount::query()->where('code', '1110')->sole()->getKey(),
        'requires_proof' => false,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create();
    $this->variant = ProductVariant::factory()->create(['base_price' => '250.00']);

    InventoryStock::factory()->for($this->variant)->for($this->warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);

    $this->lot = InventoryLot::factory()->for($this->variant, 'productVariant')->for($this->warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
});

/** Quotation created, sent and accepted: 4 x 250.00 net with 100.00 tax (1100.00 gross). */
function e2eAcceptedQuotation(): Quotation
{
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => test()->customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => test()->variant->getKey(), 'quantity' => 4, 'unit_price' => 250, 'tax_amount' => 100]],
    );

    app(QuotationService::class)->send($quotation);
    app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, test()->actor);

    return $quotation->refresh();
}

/** A planned (draft) delivery of the whole order line, tied to the order and the lot. */
function e2ePlannedDelivery(Order $order, string $quantity = '4.000000'): InventoryOperation
{
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => test()->warehouse->getKey(),
        'customer_id' => test()->customer->getKey(),
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);

    $delivery->lines()->create([
        'product_variant_id' => test()->variant->getKey(),
        'order_line_id' => $order->lines()->sole()->getKey(),
        'quantity' => $quantity,
        'unit_id' => test()->variant->unit_id,
        'inventory_lot_id' => test()->lot->getKey(),
    ]);

    return $delivery->refresh();
}

function e2eCompletedDelivery(Order $order): InventoryOperation
{
    $delivery = e2ePlannedDelivery($order);

    app(InventoryOperationService::class)->markReady($delivery, test()->actor);
    app(InventoryOperationService::class)->complete($delivery->refresh(), test()->actor);

    return $delivery->refresh();
}

/**
 * The whole chain up to an issued invoice.
 *
 * @return array{quotation: Quotation, order: Order, delivery: InventoryOperation, invoice: Invoice}
 */
function e2eIssuedSale(): array
{
    $quotation = e2eAcceptedQuotation();
    $order = app(QuotationConversionService::class)->convert($quotation);
    $delivery = e2eCompletedDelivery($order);
    $invoice = app(InvoiceService::class)->createFromDelivery(test()->actor, $delivery);
    $invoice = app(InvoiceService::class)->issue(test()->actor, $invoice);

    return ['quotation' => $quotation->refresh(), 'order' => $order, 'delivery' => $delivery, 'invoice' => $invoice];
}

function e2ePay(Invoice $invoice, string $amount): Payment
{
    $payment = app(PaymentService::class)->createDraft(test()->actor, [
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => test()->paymentMethod->getKey(),
        'amount' => $amount,
        'payment_date' => today()->toDateString(),
    ]);

    return app(PaymentService::class)->post(test()->actor, $payment, [
        ['invoice_id' => (int) $invoice->getKey(), 'amount' => $amount],
    ]);
}

function e2eBalance(string $code): string
{
    return app(AccountBalanceService::class)->balanceFor(
        ChartAccount::query()->where('code', $code)->sole(),
        includeDescendants: false,
    );
}

function e2eOnHand(): string
{
    return (string) (float) InventoryStock::query()
        ->where('product_variant_id', test()->variant->getKey())
        ->where('warehouse_id', test()->warehouse->getKey())
        ->sole()
        ->on_hand_quantity;
}

/** Stock-changing movements only; the ready-stage reservation does not move on-hand. */
function e2eStockMovementCount(): int
{
    return InventoryMovement::query()->where('movement_type', '!=', MovementType::Reservation->value)->count();
}

function e2eAssertEveryJournalBalanced(): void
{
    $unbalanced = JournalEntryLine::query()
        ->select('journal_entry_id', DB::raw('SUM(debit) AS debits'), DB::raw('SUM(credit) AS credits'))
        ->groupBy('journal_entry_id')
        ->get()
        ->filter(fn (JournalEntryLine $row): bool => bccomp((string) $row->debits, (string) $row->credits, 2) !== 0);

    expect($unbalanced)->toHaveCount(0);
}

function e2eAssertReconciled(): void
{
    $today = CarbonImmutable::today();
    $reconciliation = app(TaxRegisterService::class)->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('0.00')
        ->and($reconciliation['payable']['difference'])->toBe('0.00');
}

/** @return array<string, array{debit: string, credit: string}> keyed by account code */
function e2eEntryByAccount(JournalEntry $entry): array
{
    $byAccount = [];

    foreach ($entry->lines()->with('chartAccount')->get() as $line) {
        $code = (string) $line->chartAccount->code;
        $byAccount[$code] = [
            'debit' => bcadd($byAccount[$code]['debit'] ?? '0', (string) $line->debit, 2),
            'credit' => bcadd($byAccount[$code]['credit'] ?? '0', (string) $line->credit, 2),
        ];
    }

    return $byAccount;
}

it('E2E-01 carries a normal sale from quotation to a fully paid invoice with correct stock, ledger and tax at every stage', function (): void {
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $this->customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $this->variant->getKey(), 'quantity' => 4, 'unit_price' => 250, 'tax_amount' => 100]],
    );
    app(QuotationService::class)->send($quotation);
    expect($quotation->refresh()->status)->toBe(QuotationStatus::Sent);

    app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $this->actor);
    expect($quotation->refresh()->status)->toBe(QuotationStatus::Accepted);

    $order = app(QuotationConversionService::class)->convert($quotation);

    expect(Order::query()->count())->toBe(1)
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::ConvertedToDelivery)
        ->and($quotation->converted_order_id)->toBe($order->getKey())
        ->and((float) $order->grand_total)->toBe(1100.0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(e2eStockMovementCount())->toBe(0)
        ->and(e2eOnHand())->toBe('10');

    // Delivery: planned (draft) -> ready -> completed.
    $delivery = e2ePlannedDelivery($order);
    expect($delivery->stage)->toBe(OperationStage::Draft);

    app(InventoryOperationService::class)->markReady($delivery, $this->actor);
    expect($delivery->refresh()->stage)->toBe(OperationStage::Ready)
        ->and(e2eOnHand())->toBe('10');

    app(InventoryOperationService::class)->complete($delivery->refresh(), $this->actor);
    expect($delivery->refresh()->stage)->toBe(OperationStage::Done);

    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $delivery->getKey())
        ->sole();

    expect(e2eOnHand())->toBe('6')
        ->and($movement->movement_type)->toBe(MovementType::Sale)
        ->and((float) $movement->quantity)->toBe(-4.0)
        ->and($movement->warehouse_id)->toBe($this->warehouse->getKey())
        ->and($movement->product_variant_id)->toBe($this->variant->getKey())
        // Quotation and delivery post nothing to the ledger or the tax register.
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(TaxRecognitionEntry::query()->count())->toBe(0);

    // Invoice from the delivery, issued.
    $invoice = app(InvoiceService::class)->createFromDelivery($this->actor, $delivery);
    $invoice = app(InvoiceService::class)->issue($this->actor, $invoice);

    $entry = $invoice->journalEntries()->sole();

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->tax_total)->toBe(100.0)
        ->and((float) $invoice->total_amount)->toBe(1100.0)
        ->and(e2eEntryByAccount($entry))->toBe([
            '1200' => ['debit' => '1100.00', 'credit' => '0.00'],
            '4100' => ['debit' => '0.00', 'credit' => '1000.00'],
            '2350' => ['debit' => '0.00', 'credit' => '100.00'],
        ])
        ->and(e2eBalance('2350'))->toBe('100.00')
        ->and(e2eBalance('2300'))->toBe('0.00')
        ->and(TaxRecognitionEntry::query()->count())->toBe(0)
        ->and($invoice->recognised_tax_amount)->toBe('0.00')
        ->and(e2eOnHand())->toBe('6')
        ->and(e2eStockMovementCount())->toBe(1);

    e2eAssertEveryJournalBalanced();

    // Full manual payment.
    e2ePay($invoice, '1100.00');
    $invoice->refresh();

    expect($invoice->outstandingMinor())->toBe(0)
        ->and(app(InvoiceBalanceService::class)->status($invoice))->toBe('paid')
        ->and($invoice->amount_paid)->toBe('1100.00')
        ->and($invoice->recognised_tax_amount)->toBe('100.00')
        ->and(e2eBalance('2350'))->toBe('0.00')
        ->and(e2eBalance('2300'))->toBe('100.00')
        ->and(e2eBalance('1200'))->toBe('0.00')
        ->and(e2eBalance('1110'))->toBe('1100.00')
        ->and(e2eOnHand())->toBe('6')
        ->and(e2eStockMovementCount())->toBe(1);

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();
});

it('E2E-02 recognises tax proportionally across two partial payments and settles deferred tax to zero', function (): void {
    ['invoice' => $invoice] = e2eIssuedSale();

    expect((float) $invoice->tax_total)->toBe(100.0)
        ->and(e2eBalance('2350'))->toBe('100.00');

    // Payment 1: 40% of the 1100.00 claim.
    e2ePay($invoice, '440.00');
    $invoice->refresh();

    expect($invoice->recognised_tax_amount)->toBe('40.00')
        ->and($invoice->outstandingMinor())->toBe(66000)
        ->and(app(InvoiceBalanceService::class)->status($invoice))->toBe('partially_paid')
        ->and(e2eBalance('2300'))->toBe('40.00')
        ->and(e2eBalance('2350'))->toBe('60.00');

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();

    // Payment 2: the remainder recognises exactly the remaining tax.
    e2ePay($invoice, '660.00');
    $invoice->refresh();

    $recognised = TaxRecognitionEntry::query()->where('invoice_id', $invoice->getKey())->get();

    expect($invoice->outstandingMinor())->toBe(0)
        ->and(app(InvoiceBalanceService::class)->status($invoice))->toBe('paid')
        ->and($invoice->recognised_tax_amount)->toBe('100.00')
        ->and($recognised)->toHaveCount(2)
        ->and(bcadd((string) $recognised->sum(fn (TaxRecognitionEntry $entry): string => (string) $entry->recognised_tax_amount), '0', 2))->toBe('100.00')
        ->and($recognised->pluck('recognised_tax_amount')->all())->toBe(['40.00', '60.00'])
        ->and(e2eBalance('2300'))->toBe('100.00')
        ->and(e2eBalance('2350'))->toBe('0.00');

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();
});

it('E2E-03a refuses to complete the same delivery twice and decrements stock exactly once', function (): void {
    $order = app(QuotationConversionService::class)->convert(e2eAcceptedQuotation());
    $delivery = e2eCompletedDelivery($order);

    expect(e2eOnHand())->toBe('6');

    expect(fn () => app(InventoryOperationService::class)->complete($delivery->refresh(), $this->actor))
        ->toThrow(DomainException::class);

    expect(e2eOnHand())->toBe('6')
        ->and(InventoryMovement::query()->where('source_type', 'inventory_operation')->where('source_id', $delivery->getKey())->count())->toBe(1)
        ->and($delivery->refresh()->stage)->toBe(OperationStage::Done);
});

it('E2E-03b settles the same Stripe transaction twice into one payment, one allocation and one tax recognition', function (): void {
    PaymentMethod::factory()->create([
        'type' => PaymentMethodType::Stripe,
        'chart_account_id' => ChartAccount::query()->where('code', '1100')->sole()->getKey(),
        'is_active' => true,
        'requires_proof' => false,
    ]);

    ['invoice' => $invoice] = e2eIssuedSale();

    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Invoice::class,
        'purpose_id' => $invoice->getKey(),
        'amount_minor' => 110000,
        'currency' => 'AED',
        'payment_intent_id' => 'pi_e2e_duplicate',
    ]);

    $service = app(ProviderPaymentSettlementService::class);
    $first = $service->settle($transaction);
    $second = $service->settle($first->refresh());

    expect($second->payment_id)->toBe($first->payment_id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1)
        ->and(TaxRecognitionEntry::query()->where('invoice_id', $invoice->getKey())->count())->toBe(1)
        ->and($invoice->refresh()->amount_paid)->toBe('1100.00')
        ->and($invoice->recognised_tax_amount)->toBe('100.00')
        ->and($invoice->outstandingMinor())->toBe(0)
        ->and(e2eBalance('2300'))->toBe('100.00')
        ->and(e2eBalance('2350'))->toBe('0.00');

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();
});

it('E2E-03c refuses to confirm the same credit note twice and leaves credited amount and journals unchanged', function (): void {
    ['invoice' => $invoice] = e2eIssuedSale();

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $this->customer->getKey(),
        'issue_date' => today()->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $creditNote, 'Price correction', 1.0, 250.0, 25.0);
    app(CreditNoteService::class)->confirm($this->actor, $creditNote);

    $creditedAfterFirst = $invoice->refresh()->credited_amount;
    $journalCount = JournalEntry::query()->count();
    $recognitionCount = TaxRecognitionEntry::query()->count();

    expect($creditedAfterFirst)->toBe('275.00');

    expect(fn () => app(CreditNoteService::class)->confirm($this->actor, $creditNote->refresh()))
        ->toThrow(AuthorizationException::class);

    expect($invoice->refresh()->credited_amount)->toBe($creditedAfterFirst)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(TaxRecognitionEntry::query()->count())->toBe($recognitionCount)
        ->and($creditNote->refresh()->isConfirmed())->toBeTrue()
        // Only the 25.00 of credited tax leaves deferred, once.
        ->and(e2eBalance('2350'))->toBe('75.00');

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();
});

it('E2E-03d refuses to convert the same accepted quotation twice and keeps exactly one order', function (): void {
    $quotation = e2eAcceptedQuotation();

    $order = app(QuotationConversionService::class)->convert($quotation);

    expect(fn () => app(QuotationConversionService::class)->convert($quotation->refresh()))
        ->toThrow(InvalidQuotationTransition::class);

    expect(Order::query()->count())->toBe(1)
        ->and($quotation->refresh()->converted_order_id)->toBe($order->getKey())
        ->and($order->lines()->count())->toBe(1);
});

it('E2E-04 restores stock once and keeps tax consistent when a partly paid sale is returned and credited', function (): void {
    [
        'delivery' => $delivery,
        'invoice' => $invoice,
        'order' => $order,
    ] = e2eIssuedSale();

    e2ePay($invoice, '550.00');

    expect(e2eOnHand())->toBe('6')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('50.00');

    // Customer returns 1 of the 4 delivered units; the return is posted once.
    $deliveryLine = InventoryOperationLine::query()->where('inventory_operation_id', $delivery->getKey())->sole();

    $returns = app(InventoryReturnService::class);
    $return = $returns->createCustomerReturn($this->actor, $delivery, $this->warehouse, 'Customer returned one unit');
    $returnLine = $returns->addCustomerLine($return, $deliveryLine, '1.000000', (int) $this->lot->getKey());
    $returns->inspectLine($returnLine, InventoryReturnDisposition::Saleable, $this->actor);
    $returns->markReady($return, $this->actor);
    $returns->post($return->refresh(), $this->actor);

    expect(e2eOnHand())->toBe('7');

    expect(fn () => $returns->post($return->refresh(), $this->actor))->toThrow(DomainException::class);

    expect(e2eOnHand())->toBe('7')
        ->and(InventoryMovement::query()->where('source_type', 'inventory_return')->where('source_id', $return->getKey())->count())->toBe(1);

    // Credit note for exactly the returned line (1 x 250.00 + 25.00 tax).
    $invoiceLine = $invoice->lines()->sole();

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $this->customer->getKey(),
        'inventory_return_id' => $return->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
        'issue_date' => today()->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $creditNote, 'Returned unit', 1.0, 250.0, 25.0, $invoiceLine, $returnLine);
    app(CreditNoteService::class)->confirm($this->actor, $creditNote);

    $invoice->refresh();

    // Claim after credit: 825.00 with 75.00 effective tax, 550.00 paid -> 50.00 recognised.
    expect($invoice->credited_amount)->toBe('275.00')
        ->and($invoice->recognised_tax_amount)->toBe('50.00')
        ->and(e2eBalance('2300'))->toBe('50.00')
        ->and(e2eBalance('2350'))->toBe('25.00')
        ->and((float) e2eBalance('2350'))->toBeGreaterThanOrEqual(0.0)
        // The credit does not touch stock a second time.
        ->and(e2eOnHand())->toBe('7')
        ->and(e2eStockMovementCount())->toBe(2);

    // Source documents are retained.
    expect(Invoice::query()->whereKey($invoice->getKey())->exists())->toBeTrue()
        ->and($invoice->lines()->count())->toBe(1)
        ->and(InventoryOperation::query()->whereKey($delivery->getKey())->exists())->toBeTrue()
        ->and(Order::query()->whereKey($order->getKey())->exists())->toBeTrue()
        ->and(Payment::query()->count())->toBe(1)
        ->and($creditNote->refresh()->isConfirmed())->toBeTrue();

    e2eAssertEveryJournalBalanced();
    e2eAssertReconciled();
});
