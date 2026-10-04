<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Accounting\WriteOffData;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\InventoryReturnDisposition;
use App\Enums\RefundStatus;
use App\Enums\WriteOffReason;
use App\Models\CreditNote;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Refund;
use App\Services\Accounting\ReceivableWriteOffService;
use App\Services\Accounting\RefundService;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Payments\StripeRefundService;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * After-sales corrections: customer returns tied to credit notes, price-adjustment credits,
 * refunds (bank and card) and receivable write-offs.
 *
 * The credit note and refund headers have no creating service, so they are written with the
 * model (blameable columns follow the signed-in maker) and then driven by the services.
 */
final readonly class DemoSalesCreditScenes
{
    /**
     * Posted customer returns: `lines` is variant key => quantity returned.
     *
     * @var array<string, array{delivery: string, create: string, post: string, reason: string, lines: array<string, int>, disposition: string}>
     */
    public const array Returns = [
        'RT1' => ['delivery' => 'O13', 'create' => '2026-09-23 10:00', 'post' => '2026-09-23 14:00', 'reason' => 'Wrong product configuration ordered, whole delivery returned.',
            'lines' => ['P009-STD' => 5, 'P006-LIGHT' => 10, 'P004-40X10' => 1], 'disposition' => 'saleable'],
        'RT2' => ['delivery' => 'O12', 'create' => '2026-09-21 10:00', 'post' => '2026-09-22 11:00', 'reason' => 'Four burs sets over-ordered, returned unopened.',
            'lines' => ['P016-BASIC' => 4], 'disposition' => 'saleable'],
    ];

    /**
     * Credit notes. `return` links a posted return (goods returned, one credit line per return
     * line at the invoiced price); otherwise `lines` are [description, quantity, unit price,
     * tax, invoice line index].
     *
     * @var array<string, array{invoice: string, create: string, confirm?: string, reverse?: string, reason: CreditNoteReason, stock: CreditNoteStockConsequence, text: string, return?: string, lines?: list<array{0: string, 1: float, 2: float, 3: float, 4: int}>}>
     */
    public const array CreditNotes = [
        'CN1' => ['invoice' => 'I13', 'create' => '2026-09-24 10:00', 'confirm' => '2026-09-24 11:00', 'reason' => CreditNoteReason::SalesReturn,
            'stock' => CreditNoteStockConsequence::GoodsReturned, 'text' => 'Full credit for the returned delivery.', 'return' => 'RT1'],
        'CN2' => ['invoice' => 'I12', 'create' => '2026-09-22 14:00', 'confirm' => '2026-09-22 15:00', 'reason' => CreditNoteReason::SalesReturn,
            'stock' => CreditNoteStockConsequence::GoodsReturned, 'text' => 'Credit for four returned bur sets.', 'return' => 'RT2'],
        'CN3' => ['invoice' => 'I07', 'create' => '2026-09-21 10:00', 'confirm' => '2026-09-21 11:00', 'reason' => CreditNoteReason::PricingAdjustment,
            'stock' => CreditNoteStockConsequence::CustomerRetained, 'text' => 'Contract price correction on premium bur sets, goods kept.',
            'lines' => [['Price correction on premium bur sets', 8.0, 25.0, 10.0, 2]]],
        'CN4' => ['invoice' => 'I09', 'create' => '2026-09-18 11:00', 'confirm' => '2026-09-18 11:30', 'reason' => CreditNoteReason::CommercialDiscount,
            'stock' => CreditNoteStockConsequence::NotApplicable, 'text' => 'Goodwill discount after a late first shipment.',
            'lines' => [['Goodwill discount', 1.0, 500.0, 25.0, 0]]],
        'CN5' => ['invoice' => 'I05', 'create' => '2026-09-30 14:00', 'reason' => CreditNoteReason::PricingAdjustment,
            'stock' => CreditNoteStockConsequence::NotApplicable, 'text' => 'Price adjustment requested by the customer, awaiting approval.',
            'lines' => [['Price adjustment on composite resin', 10.0, 10.0, 5.0, 0]]],
        'CN6' => ['invoice' => 'I10', 'create' => '2026-09-28 10:30', 'confirm' => '2026-09-28 11:00', 'reverse' => '2026-09-29 09:30', 'reason' => CreditNoteReason::Other,
            'stock' => CreditNoteStockConsequence::NotApplicable, 'text' => 'Issued against the wrong invoice, reversed by the administrator.',
            'lines' => [['Credit entered in error', 1.0, 200.0, 10.0, 0]]],
        'CN8' => ['invoice' => 'I16', 'create' => '2026-09-28 11:00', 'confirm' => '2026-09-28 11:30', 'reason' => CreditNoteReason::Other,
            'stock' => CreditNoteStockConsequence::CustomerRetained, 'text' => 'Damaged outer packaging on two grafts, goods kept at a discount.',
            'lines' => [['Packaging damage allowance', 2.0, 100.0, 10.0, 1]]],
        'CN9' => ['invoice' => 'I11', 'create' => '2026-10-01 10:00', 'confirm' => '2026-10-01 10:30', 'reason' => CreditNoteReason::PricingAdjustment,
            'stock' => CreditNoteStockConsequence::NotApplicable, 'text' => 'Putty price aligned with the framework agreement.',
            'lines' => [['Putty price alignment', 20.0, 10.0, 10.0, 0]]],
    ];

    /**
     * Refunds funded by a credit note. `method` names the payment method; `stripe` refunds the
     * original card transaction. `approve`/`pay` null = stop at the previous status;
     * `cancel` cancels the draft.
     *
     * @var array<string, array{credit: string, amount: string, method: string, create: string, refund_date: string, reason: string, approve?: string, pay?: string, cancel?: string, stripe?: string}>
     */
    public const array Refunds = [
        'RF1' => ['credit' => 'CN4', 'amount' => '525.00', 'method' => 'Customer Bank Transfer', 'create' => '2026-09-21 10:00', 'refund_date' => '2026-09-23',
            'reason' => 'Refund of the goodwill discount already collected.', 'approve' => '2026-09-22 10:00', 'pay' => '2026-09-23 10:00'],
        'RF5' => ['credit' => 'CN8', 'amount' => '2100.00', 'method' => 'Stripe Card Payments', 'create' => '2026-09-29 09:00', 'refund_date' => '2026-09-29',
            'reason' => 'Entered with the wrong amount.', 'cancel' => '2026-09-29 09:30'],
        'RF3' => ['credit' => 'CN8', 'amount' => '210.00', 'method' => 'Stripe Card Payments', 'create' => '2026-09-29 10:00', 'refund_date' => '2026-09-30',
            'reason' => 'Packaging damage allowance returned to the card.', 'approve' => '2026-09-29 11:00', 'stripe' => '2026-09-30 10:00'],
        'RF2' => ['credit' => 'CN9', 'amount' => '100.00', 'method' => 'Customer Bank Transfer', 'create' => '2026-10-02 09:00', 'refund_date' => '2026-10-02',
            'reason' => 'First part of the price alignment refund.', 'approve' => '2026-10-02 10:00'],
        'RF4' => ['credit' => 'CN9', 'amount' => '110.00', 'method' => 'Customer Bank Transfer', 'create' => '2026-10-02 11:00', 'refund_date' => '2026-10-05',
            'reason' => 'Remaining price alignment refund, requested.'],
    ];

    /**
     * Receivable write-offs of tax-free service invoices.
     *
     * @var array<string, array{invoice: string, record: string, approve?: string, reason: WriteOffReason, text: string}>
     */
    public const array WriteOffs = [
        'W1' => ['invoice' => 'Z1', 'record' => '2026-09-29 10:00', 'approve' => '2026-09-30 10:00', 'reason' => WriteOffReason::DisputedAndAbandoned,
            'text' => 'Maintenance visit disputed by the clinic; commercial settlement agreed to waive the balance.'],
        'W2' => ['invoice' => 'Z2', 'record' => '2026-10-01 11:00', 'reason' => WriteOffReason::Untraceable,
            'text' => 'Commissioning contact left the centre; collection attempts unanswered, awaiting chief accountant review.'],
    ];

    public function __construct(
        private DemoSalesKit $kit,
        private DemoSalesTimeline $timeline,
    ) {}

    public function register(): void
    {
        foreach (self::Returns as $code => $return) {
            $this->timeline->add($return['create'], "return {$code} drafted", fn () => $this->draftReturn($code));
            $this->timeline->add($return['post'], "return {$code} posted", fn () => $this->postReturn($code));
        }

        foreach (self::CreditNotes as $code => $note) {
            $this->timeline->add($note['create'], "credit note {$code} drafted", fn () => $this->draftCreditNote($code));

            if (isset($note['confirm'])) {
                $this->timeline->add($note['confirm'], "credit note {$code} confirmed", fn () => $this->confirmCreditNote($code));
            }

            if (isset($note['reverse'])) {
                $this->timeline->add($note['reverse'], "credit note {$code} reversed", fn () => $this->reverseCreditNote($code));
            }
        }

        foreach (self::Refunds as $code => $refund) {
            $this->timeline->add($refund['create'], "refund {$code} requested", fn () => $this->requestRefund($code));

            if (isset($refund['approve'])) {
                $this->timeline->add($refund['approve'], "refund {$code} approved", fn () => $this->approveRefund($code));
            }

            if (isset($refund['pay'])) {
                $this->timeline->add($refund['pay'], "refund {$code} paid", fn () => $this->payRefund($code));
            }

            if (isset($refund['stripe'])) {
                $this->timeline->add($refund['stripe'], "refund {$code} paid through Stripe", fn () => $this->stripeRefund($code));
            }

            if (isset($refund['cancel'])) {
                $this->timeline->add($refund['cancel'], "refund {$code} cancelled", fn () => $this->cancelRefund($code));
            }
        }

        foreach (self::WriteOffs as $code => $writeOff) {
            $this->timeline->add($writeOff['record'], "write-off {$code} recorded", fn () => $this->recordWriteOff($code));

            if (isset($writeOff['approve'])) {
                $this->timeline->add($writeOff['approve'], "write-off {$code} approved", fn () => $this->approveWriteOff($code));
            }
        }
    }

    private function draftReturn(string $code): void
    {
        $definition = self::Returns[$code];
        $operations = $this->kit->context->as('operations');
        $service = app(InventoryReturnService::class);
        $delivery = $this->kit->deliveries[$definition['delivery']][0]->refresh();

        $return = $service->createCustomerReturn($operations, $delivery, $delivery->sourceWarehouse ?? throw new LogicException("Delivery for return {$code} has no source warehouse."), $definition['reason'], "[DEMO-SALES] {$code}");

        foreach ($definition['lines'] as $key => $quantity) {
            $variant = $this->kit->variant($key);

            /** @var InventoryOperationLine $deliveryLine */
            $deliveryLine = $delivery->lines()->where('product_variant_id', $variant->getKey())->orderBy('id')->firstOrFail();

            $service->addCustomerLine(
                $return,
                $deliveryLine,
                number_format((float) $quantity, 6, '.', ''),
                $deliveryLine->inventory_lot_id === null ? null : (int) $deliveryLine->inventory_lot_id,
                $deliveryLine->serialized_inventory_unit_id === null ? null : (int) $deliveryLine->serialized_inventory_unit_id,
            );
        }

        $this->kit->returns[$code] = $return->refresh();
    }

    private function postReturn(string $code): void
    {
        $definition = self::Returns[$code];
        $operations = $this->kit->context->as('operations');
        $service = app(InventoryReturnService::class);
        $return = $this->kit->returns[$code]->refresh();

        foreach ($return->lines()->orderBy('id')->get() as $line) {
            $service->inspectLine($line, InventoryReturnDisposition::from($definition['disposition']), $operations, 'Inspected on receipt, packaging intact.');
        }

        $service->markReady($return->refresh(), $operations);
        $this->kit->returns[$code] = $service->post($return->refresh(), $operations);
    }

    private function draftCreditNote(string $code): void
    {
        $definition = self::CreditNotes[$code];
        $billing = $this->kit->context->as('billing');
        $invoice = $this->kit->invoices[$definition['invoice']]->refresh();
        $return = isset($definition['return']) ? $this->kit->returns[$definition['return']]->refresh() : null;

        $creditNote = CreditNote::query()->create([
            'credit_note_number' => app(DocumentNumberGenerator::class)->next(CreditNote::withTrashed(), 'credit_note_number', 'CN-'),
            'invoice_id' => $invoice->getKey(),
            'customer_id' => $invoice->customer_id,
            'inventory_return_id' => $return?->getKey(),
            'reason' => "[DEMO-SALES] {$definition['text']}",
            'reason_category' => $definition['reason'],
            'stock_consequence' => $definition['stock'],
            'issue_date' => Carbon::parse($definition['create'])->toDateString(),
            'subtotal' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'status' => 'draft',
        ]);

        $service = app(CreditNoteService::class);

        if ($return !== null) {
            foreach ($return->lines()->orderBy('id')->get() as $returnLine) {
                $this->addReturnedGoodsLine($service, $creditNote, $invoice, $returnLine);
            }
        } else {
            $invoiceLines = $invoice->lines()->orderBy('id')->get();

            foreach ($definition['lines'] as [$description, $quantity, $unitPrice, $tax, $lineIndex]) {
                $service->addLine($billing, $creditNote, $description, $quantity, $unitPrice, $tax, $invoiceLines[$lineIndex]);
            }
        }

        $this->kit->creditNotes[$code] = $creditNote->refresh();
    }

    private function addReturnedGoodsLine(CreditNoteService $service, CreditNote $creditNote, Invoice $invoice, InventoryReturnLine $returnLine): void
    {
        $deliveryLine = $returnLine->originalOperationLine;

        /** @var InvoiceLine $invoiceLine */
        $invoiceLine = $invoice->lines()->where('order_line_id', $deliveryLine?->order_line_id)->firstOrFail();
        $quantity = (float) $returnLine->transaction_quantity;
        $tax = round((float) $invoiceLine->tax_amount * $quantity / (float) $invoiceLine->quantity, 2);

        $service->addLine(
            $this->kit->context->as('billing'),
            $creditNote,
            'Returned: '.$invoiceLine->description,
            $quantity,
            (float) $invoiceLine->unit_price,
            $tax,
            $invoiceLine,
            $returnLine,
        );
    }

    private function confirmCreditNote(string $code): void
    {
        $this->kit->creditNotes[$code] = app(CreditNoteService::class)->confirm(
            $this->kit->context->as('sales_manager'),
            $this->kit->creditNotes[$code]->refresh(),
        );
    }

    private function reverseCreditNote(string $code): void
    {
        $this->kit->creditNotes[$code] = app(CreditNoteService::class)->reverse(
            $this->kit->context->as('admin'),
            $this->kit->creditNotes[$code]->refresh(),
        );
    }

    private function requestRefund(string $code): void
    {
        $definition = self::Refunds[$code];
        $this->kit->context->as('accountant');
        $creditNote = $this->kit->creditNotes[$definition['credit']]->refresh();

        $this->kit->refunds[$code] = Refund::query()->create([
            'refund_number' => app(DocumentNumberGenerator::class)->next(Refund::withTrashed(), 'refund_number', 'REF-'),
            'customer_id' => $creditNote->customer_id,
            'credit_note_id' => $creditNote->getKey(),
            'invoice_id' => $creditNote->invoice_id,
            'payment_method_id' => $this->kit->method($definition['method'])->getKey(),
            'refund_date' => $definition['refund_date'],
            'amount' => $definition['amount'],
            'reason' => "[DEMO-SALES] {$definition['reason']}",
            'status' => RefundStatus::Draft,
        ]);
    }

    private function approveRefund(string $code): void
    {
        $this->kit->refunds[$code] = app(RefundService::class)->approve(
            $this->kit->context->as('chief_accountant'),
            $this->kit->refunds[$code]->refresh(),
        );
    }

    private function payRefund(string $code): void
    {
        $this->kit->refunds[$code] = app(RefundService::class)->pay(
            $this->kit->context->as('chief_accountant'),
            $this->kit->refunds[$code]->refresh(),
        );
    }

    private function stripeRefund(string $code): void
    {
        $definition = self::Refunds[$code];
        $creditNote = $this->kit->creditNotes[$definition['credit']]->refresh();

        $transaction = $this->kit->transactions[$this->cardTransactionFor((int) $creditNote->invoice_id)]->refresh();

        $this->kit->refunds[$code] = app(StripeRefundService::class)->refund(
            $this->kit->context->as('chief_accountant'),
            $this->kit->refunds[$code]->refresh(),
            $transaction,
            (int) round(((float) $definition['amount']) * 100),
        );
    }

    private function cancelRefund(string $code): void
    {
        $this->kit->refunds[$code] = app(RefundService::class)->cancel(
            $this->kit->context->as('accountant'),
            $this->kit->refunds[$code]->refresh(),
        );
    }

    private function recordWriteOff(string $code): void
    {
        $definition = self::WriteOffs[$code];
        $invoice = $this->kit->invoices[$definition['invoice']]->refresh();

        $this->kit->writeOffs[$code] = app(ReceivableWriteOffService::class)->record(
            new WriteOffData(
                (int) $invoice->customer_id,
                DemoContext::keyOf($invoice),
                $invoice->outstandingMinor(),
                $definition['reason'],
                $definition['text'],
            ),
            $this->kit->context->as('accountant'),
        );
    }

    private function approveWriteOff(string $code): void
    {
        $this->kit->writeOffs[$code] = app(ReceivableWriteOffService::class)->approve(
            $this->kit->writeOffs[$code]->refresh(),
            $this->kit->context->as('chief_accountant'),
        );
    }

    /** The settled card transaction that paid the given invoice. */
    private function cardTransactionFor(int $invoiceId): string
    {
        foreach (DemoSalesCollectionScenes::Card as $code => $card) {
            if ($card['kind'] === 'invoice' && $card['outcome'] === 'settled'
                && ($this->kit->invoices[$card['target']]->getKey() === $invoiceId)) {
                return $code;
            }
        }

        throw new LogicException("No settled card payment found for invoice [{$invoiceId}].");
    }
}
