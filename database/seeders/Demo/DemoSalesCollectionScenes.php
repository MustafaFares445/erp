<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Invoice;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ProviderPaymentSettlementService;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Payments\StripePaymentReconciliationService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Customer collections: manual payments (bank, cash, cheque, exchange house), an unallocated
 * customer deposit, and card payments driven through the in-memory Stripe client
 * (checkout -> provider confirmation -> reconciliation -> settlement).
 */
final class DemoSalesCollectionScenes
{
    /**
     * Manual payments. `invoice` null = unallocated customer deposit for `customer`;
     * `amount` 'rest' = the invoice's outstanding balance on that day.
     *
     * `reverse` voids the payment later (administrator); `post` false leaves it as a draft.
     *
     * @var array<string, array{invoice?: string, customer?: string, method: string, amount: string, at: string, ref: string, note: string, reverse?: string, post?: bool}>
     */
    public const array Manual = [
        'PM01' => ['invoice' => 'I01', 'method' => 'Operating Bank Transfer', 'amount' => '3000.00', 'at' => '2026-09-13 10:00', 'ref' => 'BNK-2026-0913-ALN', 'note' => 'First instalment of the Al Noor invoice.'],
        'PM02' => ['invoice' => 'I09', 'method' => 'Cash Desk', 'amount' => 'rest', 'at' => '2026-09-14 14:00', 'ref' => 'CASH-0914-FH', 'note' => 'Paid in cash at the front desk.'],
        'PM03' => ['customer' => 'C08', 'method' => 'Operating Bank Transfer', 'amount' => '1500.00', 'at' => '2026-09-15 11:00', 'ref' => 'BNK-2026-0915-GVC', 'note' => 'Advance on account, no invoice yet.'],
        'PM04' => ['invoice' => 'I07', 'method' => 'Cheque Deposit', 'amount' => '2000.00', 'at' => '2026-09-18 10:00', 'ref' => 'CHQ-004417', 'note' => 'Cheque deposited for the first instalment.'],
        'PM05' => ['invoice' => 'I01', 'method' => 'Operating Bank Transfer', 'amount' => '2500.00', 'at' => '2026-09-22 10:30', 'ref' => 'BNK-2026-0922-ALN', 'note' => 'Second instalment of the Al Noor invoice.'],
        'PM06' => ['invoice' => 'I08', 'method' => 'Cash Desk', 'amount' => '1000.00', 'at' => '2026-09-25 10:00', 'ref' => 'CASH-0925-NCM', 'note' => 'Part payment in cash.'],
        'PM07' => ['invoice' => 'I11', 'method' => 'Exchange House Remittance', 'amount' => '3000.00', 'at' => '2026-09-26 10:00', 'ref' => 'EXH-88213', 'note' => 'Remittance through the exchange house.'],
        'PM08' => ['invoice' => 'I12', 'method' => 'Operating Bank Transfer', 'amount' => '1500.00', 'at' => '2026-09-26 11:00', 'ref' => 'BNK-2026-0926-PDL', 'note' => 'Part payment after the return credit.'],
        'PM09' => ['invoice' => 'I11', 'method' => 'Cash Desk', 'amount' => 'rest', 'at' => '2026-09-29 14:00', 'ref' => 'CASH-0929-AHD', 'note' => 'Balance settled in cash.'],
        'PM10' => ['invoice' => 'I10', 'method' => 'Operating Bank Transfer', 'amount' => 'rest', 'at' => '2026-09-30 11:00', 'ref' => 'BNK-2026-0930-FMC', 'note' => 'Paid in full by bank transfer.'],
        'PM12' => ['invoice' => 'I05', 'method' => 'Operating Bank Transfer', 'amount' => '500.00', 'at' => '2026-09-24 10:00', 'ref' => 'BNK-2026-0924-HDC', 'note' => 'Transfer matched to the wrong customer account.', 'reverse' => '2026-09-25 09:30'],
        'PM13' => ['invoice' => 'I06', 'method' => 'Cheque Deposit', 'amount' => 'rest', 'at' => '2026-10-02 09:00', 'ref' => 'CHQ-330871', 'note' => 'Cheque received, not yet deposited.', 'post' => false],
        'PM11' => ['invoice' => 'I08', 'method' => 'Cheque Deposit', 'amount' => '800.00', 'at' => '2026-09-30 15:00', 'ref' => 'CHQ-120045', 'note' => 'Second part payment by cheque.'],
    ];

    /**
     * Card payments through the Stripe flow. `kind`: invoice | order (deposit); `outcome`:
     * settled | failed | requires_action | unsettled (paid at the provider, not yet posted).
     *
     * @var array<string, array{kind: string, target: string, customer: string, outcome: string, at: string, amount?: float, code?: string}>
     */
    public const array Card = [
        'ST01' => ['kind' => 'invoice', 'target' => 'I02', 'customer' => 'C02', 'outcome' => 'settled', 'at' => '2026-09-18 10:00'],
        'ST02' => ['kind' => 'order', 'target' => 'O04', 'customer' => 'C12', 'outcome' => 'settled', 'at' => '2026-09-19 11:00', 'amount' => 2000.0],
        'ST03' => ['kind' => 'invoice', 'target' => 'I16', 'customer' => 'C11', 'outcome' => 'settled', 'at' => '2026-09-22 10:00'],
        'ST04' => ['kind' => 'invoice', 'target' => 'I03', 'customer' => 'C09', 'outcome' => 'failed', 'at' => '2026-09-25 14:00', 'code' => 'card_declined'],
        'ST05' => ['kind' => 'invoice', 'target' => 'I07', 'customer' => 'C04', 'outcome' => 'settled', 'at' => '2026-09-29 10:00'],
        'ST06' => ['kind' => 'order', 'target' => 'O18', 'customer' => 'C13', 'outcome' => 'settled', 'at' => '2026-09-29 16:00', 'amount' => 1000.0],
        'ST07' => ['kind' => 'invoice', 'target' => 'IR', 'customer' => 'C14', 'outcome' => 'settled', 'at' => '2026-09-30 10:00'],
        'ST08' => ['kind' => 'invoice', 'target' => 'I06', 'customer' => 'C06', 'outcome' => 'requires_action', 'at' => '2026-10-02 11:00'],
        'ST09' => ['kind' => 'invoice', 'target' => 'I04', 'customer' => 'C12', 'outcome' => 'unsettled', 'at' => '2026-10-02 15:00'],
    ];

    public function __construct(
        private readonly DemoSalesKit $kit,
        private readonly DemoSalesTimeline $timeline,
    ) {}

    public function register(): void
    {
        foreach (self::Manual as $code => $payment) {
            $this->timeline->add($payment['at'], "payment {$code} recorded", fn () => $this->postManual($code));

            if (isset($payment['reverse'])) {
                $this->timeline->add($payment['reverse'], "payment {$code} reversed", fn () => $this->reverse($code));
            }
        }

        foreach (self::Card as $code => $card) {
            $this->timeline->add($card['at'], "card payment {$code} ({$card['outcome']})", fn () => $this->card($code));
        }
    }

    private function postManual(string $code): void
    {
        $payment = self::Manual[$code];
        $billing = $this->kit->context->as('billing');
        $method = $this->kit->method($payment['method']);
        $invoice = isset($payment['invoice']) ? $this->kit->invoices[$payment['invoice']]->refresh() : null;
        $customerId = $invoice instanceof Invoice ? $invoice->customer_id : $this->kit->customer((string) $payment['customer'])->getKey();

        $amount = $payment['amount'] === 'rest'
            ? number_format($invoice instanceof Invoice ? $invoice->outstandingAmount() : 0.0, 2, '.', '')
            : $payment['amount'];

        $draft = app(PaymentService::class)->createDraft($billing, [
            'customer_id' => $customerId,
            'payment_method_id' => $method->getKey(),
            'amount' => $amount,
            'currency' => 'AED',
            'payment_date' => Carbon::parse($payment['at'])->toDateString(),
            'external_reference' => $payment['ref'],
            'notes' => "[DEMO-SALES] {$payment['note']}",
        ], $method->requires_proof ? $this->proofFile($payment['ref']) : null);

        if (($payment['post'] ?? true) === false) {
            $this->kit->payments[$code] = $draft;

            return;
        }

        $allocations = $invoice instanceof Invoice ? [['invoice_id' => (int) $invoice->getKey(), 'amount' => (float) $amount]] : [];

        $this->kit->payments[$code] = app(PaymentService::class)->post($billing, $draft, $allocations);
    }

    private function reverse(string $code): void
    {
        $this->kit->payments[$code] = app(PaymentService::class)->reverse(
            $this->kit->context->as('admin'),
            $this->kit->payments[$code]->refresh(),
        );
    }

    private function card(string $code): void
    {
        $card = self::Card[$code];
        $fake = app(StripeClientInterface::class);

        if (! $fake instanceof FakeStripeClient) {
            throw new LogicException('Demo seeding must run against the in-memory Stripe client.');
        }

        $customer = $this->kit->customer($card['customer']);
        $checkout = app(StripeCheckoutService::class);
        $urls = ['https://portal.ierp.test/payments/success', 'https://portal.ierp.test/payments/cancel'];

        $transaction = $card['kind'] === 'order'
            ? $checkout->createForOrder($customer, $this->kit->orders[$card['target']]->refresh(), (float) $card['amount'], ...$urls)
            : $checkout->createForInvoice($customer, $this->kit->invoices[$card['target']]->refresh(), ...$urls);

        $intent = (string) $transaction->payment_intent_id;

        match ($card['outcome']) {
            'failed' => $fake->markFailed($intent, $card['code'] ?? 'card_declined', 'Your card was declined.'),
            'requires_action' => $fake->paymentIntents[$intent] = new StripePaymentIntentData(
                $intent,
                'requires_action',
                (int) $transaction->amount_minor,
                'AED',
                null,
            ),
            default => $fake->markSucceeded($intent),
        };

        $transaction = app(StripePaymentReconciliationService::class)->reconcile($transaction);

        if ($card['outcome'] === 'settled') {
            $transaction = app(ProviderPaymentSettlementService::class)->settle($transaction);
        }

        $this->kit->transactions[$code] = $transaction->refresh();
    }

    private function proofFile(string $reference): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'demo-proof-'.preg_replace('/[^A-Za-z0-9]+/', '-', $reference).'-'.(++$this->kit->proofCounter).'.pdf';
        file_put_contents($path, "%PDF-1.4\n% Demo remittance advice {$reference}\n%%EOF\n");

        return $path;
    }
}
