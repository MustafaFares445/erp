<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
use Illuminate\Support\Str;

/**
 * Creates a Stripe Checkout session for one of the three payment purposes
 * the Customer App V1 plan names (§9): Order prepayment/deposit, an
 * outstanding Invoice, or a chargeable Support Ticket.
 *
 * Every payable amount is either fully server-derived (Invoice/Ticket) or
 * validated against a server-known ceiling (Order) — never trusted from a
 * client as-is, per the plan's payment-purpose rule. No HTTP/webhook
 * adapter exists yet; a future controller calls these methods and redirects
 * to the returned {@see PaymentTransaction}'s checkout URL.
 */
final readonly class StripeCheckoutService
{
    public function __construct(
        private StripeClientInterface $client,
        private CurrencyCatalogService $currencies,
    ) {}

    public function createForInvoice(CustomerProfile $customer, Invoice $invoice, string $successUrl, string $cancelUrl): PaymentTransaction
    {
        if ($invoice->customer_id !== $customer->getKey()) {
            throw new DomainException('This invoice does not belong to the paying customer.');
        }

        $outstanding = $invoice->outstandingAmount();

        if ($outstanding <= 0.0) {
            throw new DomainException('This invoice has no outstanding balance to pay.');
        }

        return $this->createSession(
            $customer,
            $invoice,
            JournalEntryLine::toMinorUnits($outstanding),
            $this->currencies->defaultCode(),
            $successUrl,
            $cancelUrl,
        );
    }

    /**
     * `$requestedAmount` is a proposed prepayment/deposit amount (e.g. a
     * policy-driven percentage computed by the caller) — validated against
     * the order's grand total as a ceiling, never accepted verbatim as the
     * "correct" amount the way an invoice's outstanding balance is.
     */
    public function createForOrder(CustomerProfile $customer, Order $order, float $requestedAmount, string $successUrl, string $cancelUrl): PaymentTransaction
    {
        if ($order->customer_id !== $customer->getKey()) {
            throw new DomainException('This order does not belong to the paying customer.');
        }

        if ($requestedAmount <= 0.0) {
            throw new DomainException('The payment amount must be positive.');
        }

        if ($requestedAmount > (float) $order->grand_total) {
            throw new DomainException('The payment amount cannot exceed the order total.');
        }

        return $this->createSession(
            $customer,
            $order,
            JournalEntryLine::toMinorUnits($requestedAmount),
            $this->currencies->defaultCode(),
            $successUrl,
            $cancelUrl,
        );
    }

    public function createForTicket(CustomerProfile $customer, TicketPaymentLink $link, string $successUrl, string $cancelUrl): PaymentTransaction
    {
        $ticket = $link->ticket;

        if ($ticket === null || $ticket->customer_id !== $customer->getKey()) {
            throw new DomainException('This ticket payment does not belong to the paying customer.');
        }

        if ($link->status !== PaymentLinkStatus::Pending) {
            throw new DomainException('This ticket payment is no longer pending.');
        }

        return $this->createSession(
            $customer,
            $link,
            JournalEntryLine::toMinorUnits((float) $link->amount),
            $this->currencies->normalizeActive($link->currency),
            $successUrl,
            $cancelUrl,
        );
    }

    private function createSession(
        CustomerProfile $customer,
        Invoice|Order|TicketPaymentLink $purpose,
        int $amountMinor,
        string $currency,
        string $successUrl,
        string $cancelUrl,
    ): PaymentTransaction {
        $idempotencyKey = (string) Str::uuid();
        $customerKey = $customer->getKey();
        $purposeKey = $purpose->getKey();

        $session = $this->client->createCheckoutSession([
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'customer_reference' => is_scalar($customerKey) ? (string) $customerKey : '',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'purpose_type' => $purpose::class,
                'purpose_id' => is_scalar($purposeKey) ? (string) $purposeKey : '',
            ],
        ]);

        return PaymentTransaction::query()->create([
            'customer_id' => $customer->getKey(),
            'provider' => PaymentProvider::Stripe,
            'purpose_type' => $purpose::class,
            'purpose_id' => $purpose->getKey(),
            'checkout_session_id' => $session->id,
            'payment_intent_id' => $session->paymentIntentId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => PaymentTransactionStatus::Pending,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
