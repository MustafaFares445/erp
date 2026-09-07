<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Sales\InvoiceBalanceService;
use DomainException;

final readonly class PaymentAllocationService
{
    public function __construct(private InvoiceBalanceService $balances) {}

    public function allocate(Payment $payment, int $invoiceId, float $amount): PaymentAllocation
    {
        if ($amount <= 0.0) {
            throw new DomainException('A payment allocation must be greater than zero.');
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->sole();

        if (! $invoice->isIssued()) {
            throw new DomainException('Payments can only be allocated to issued invoices.');
        }

        if ((int) $invoice->customer_id !== (int) $payment->customer_id) {
            throw new DomainException('A payment cannot be allocated to another customer.');
        }

        $outstanding = $invoice->outstandingAmount();

        if ($amount - $outstanding > 0.00001) {
            throw new DomainException(
                sprintf('Allocation %.2f exceeds invoice outstanding %.2f.', $amount, $outstanding),
            );
        }

        if ($payment->allocations()->where('invoice_id', $invoice->getKey())->exists()) {
            throw new DomainException('A payment may allocate to the same invoice only once.');
        }

        $allocation = $payment->allocations()->create([
            'invoice_id' => $invoice->getKey(),
            'amount' => round($amount, 2),
        ]);

        $invoice->forceFill([
            'amount_paid' => round((float) $invoice->amount_paid + $amount, 2),
        ])->save();

        $this->balances->syncInvoice($invoice);
        $this->balances->syncOrder($invoice->order);

        return $allocation->setRelation('invoice', $invoice->refresh());
    }

    public function restore(PaymentAllocation $allocation): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->sole();

        $invoice->forceFill([
            'amount_paid' => max(0.0, round((float) $invoice->amount_paid - (float) $allocation->amount, 2)),
        ])->save();

        $this->balances->syncInvoice($invoice);
        $this->balances->syncOrder($invoice->order);

        return $invoice->refresh();
    }
}
