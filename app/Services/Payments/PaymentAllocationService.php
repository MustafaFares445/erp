<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\OrderPaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use DomainException;

final readonly class PaymentAllocationService
{
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

        $allocation = $payment->allocations()->create([
            'invoice_id' => $invoice->getKey(),
            'amount' => round($amount, 2),
        ]);

        $invoice->forceFill([
            'amount_paid' => round((float) $invoice->amount_paid + $amount, 2),
            'status' => $this->invoiceStatusAfterBalance($invoice, $amount),
        ])->save();

        $this->syncOrderPaymentStatus($invoice->order);

        return $allocation->setRelation('invoice', $invoice->refresh());
    }

    public function restore(PaymentAllocation $allocation): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->sole();

        $invoice->forceFill([
            'amount_paid' => max(0.0, round((float) $invoice->amount_paid - (float) $allocation->amount, 2)),
        ])->save();

        $invoice->forceFill(['status' => $this->balanceDrivenStatus($invoice)])->save();
        $this->syncOrderPaymentStatus($invoice->order);

        return $invoice->refresh();
    }

    private function invoiceStatusAfterBalance(Invoice $invoice, float $newAllocation): string
    {
        $paid = (float) $invoice->amount_paid + $newAllocation;
        $claim = max(0.0, (float) $invoice->total_amount - (float) $invoice->credited_amount);

        return $paid + 0.00001 >= $claim ? 'paid' : 'partially_paid';
    }

    private function balanceDrivenStatus(Invoice $invoice): string
    {
        $claim = max(0.0, (float) $invoice->total_amount - (float) $invoice->credited_amount);
        $paid = (float) $invoice->amount_paid;

        if ((float) $invoice->credited_amount + 0.00001 >= (float) $invoice->total_amount) {
            return 'credited';
        }

        if ($paid + 0.00001 >= $claim && $claim > 0.0) {
            return 'paid';
        }

        if ($paid > 0.0) {
            return 'partially_paid';
        }

        return $invoice->sent_at !== null ? 'sent' : 'issued';
    }

    private function syncOrderPaymentStatus(?Order $order): void
    {
        if (! $order instanceof Order) {
            return;
        }

        $invoices = $order->invoices()->whereNotNull('issued_at')->get();

        if ($invoices->isEmpty()) {
            $order->forceFill(['payment_status' => OrderPaymentStatus::Unpaid])->save();
            return;
        }

        $total = (float) $invoices->sum('total_amount');
        $covered = (float) $invoices->sum(
            fn (Invoice $invoice): float => (float) $invoice->amount_paid + (float) $invoice->credited_amount,
        );

        $status = $covered <= 0.0
            ? OrderPaymentStatus::Unpaid
            : ($covered + 0.00001 >= $total ? OrderPaymentStatus::Paid : OrderPaymentStatus::PartiallyPaid);

        $order->forceFill(['payment_status' => $status])->save();
    }
}
