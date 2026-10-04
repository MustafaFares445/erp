<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderFinancialProjection;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Support\Collection;

/**
 * One canonical financial projection for an Order (IERP_ORDER_CUSTOMER_CLOSE_PAYMENT_FLOW
 * plan §5.3). Never duplicates balance truth: invoice payment/credit/write-off figures are
 * always read through {@see Invoice::outstandingMinor()}, and Order prepayment/deposit
 * figures are always read through posted, non-reversed {@see Payment} evidence linked by an
 * Order-purpose {@see PaymentTransaction}.
 */
final readonly class OrderFinancialProjectionService
{
    public function project(Order $order): OrderFinancialProjection
    {
        [$postedPrepayments, $unallocatedDeposit] = $this->orderPrepayments($order);

        $invoices = $order->relationLoaded('invoices')
            ? $order->invoices->loadMissing('writeOffs')
            : $order->invoices()->with('writeOffs')->get();
        $issued = $invoices->filter(fn (Invoice $invoice): bool => $invoice->isIssued());
        $draftCount = $invoices->filter(fn (Invoice $invoice): bool => $invoice->isDraft())->count();

        $issuedInvoiceTotal = round($this->sum($issued, 'total_amount'), 2);
        $invoicePaidAmount = round($this->sum($issued, 'amount_paid'), 2);
        $invoiceCreditedAmount = round($this->sum($issued, 'credited_amount'), 2);
        $invoiceWrittenOffAmount = round(
            $issued->sum(fn (Invoice $invoice): float => $invoice->writtenOffAmountMinor() / 100),
            2,
        );
        $invoiceOutstandingAmount = round(
            $issued->sum(fn (Invoice $invoice): float => $invoice->outstandingMinor() / 100),
            2,
        );

        return new OrderFinancialProjection(
            orderTotal: round((float) $order->grand_total, 2),
            customerDepositCollected: round($unallocatedDeposit, 2),
            issuedInvoiceTotal: $issuedInvoiceTotal,
            invoicePaidAmount: $invoicePaidAmount,
            invoiceCreditedAmount: $invoiceCreditedAmount,
            invoiceWrittenOffAmount: $invoiceWrittenOffAmount,
            invoiceOutstandingAmount: $invoiceOutstandingAmount,
            draftInvoiceCount: $draftCount,
            issuedInvoiceCount: $issued->count(),
            financiallySettled: $issued->isNotEmpty() && $invoiceOutstandingAmount <= 0.005,
        );
    }

    /** @return array{0: float, 1: float} posted prepayments, unallocated deposit remainder */
    private function orderPrepayments(Order $order): array
    {
        if ($order->relationLoaded('workflowPaymentTransactions')) {
            $payments = $order->workflowPaymentTransactions
                ->where('status', PaymentTransactionStatus::Succeeded)
                ->map(static fn (PaymentTransaction $transaction): ?Payment => $transaction->payment)
                ->filter(static fn (?Payment $payment): bool => $payment instanceof Payment
                    && $payment->status === PaymentStatus::Posted
                    && $payment->reversed_at === null);

            $posted = 0.0;
            $unallocated = 0.0;

            foreach ($payments as $payment) {
                $amount = (float) $payment->amount;
                $allocatedValue = $payment->allocations->sum('amount');
                $allocated = is_numeric($allocatedValue) ? (float) $allocatedValue : 0.0;
                $posted += $amount;
                $unallocated += max(0.0, round($amount - $allocated, 2));
            }

            return [$posted, $unallocated];
        }

        $paymentIds = PaymentTransaction::query()
            ->where('purpose_type', Order::class)
            ->where('purpose_id', $order->getKey())
            ->where('status', PaymentTransactionStatus::Succeeded->value)
            ->whereNotNull('payment_id')
            ->pluck('payment_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->all();

        if ($paymentIds === []) {
            return [0.0, 0.0];
        }

        $payments = Payment::query()
            ->whereKey($paymentIds)
            ->where('status', PaymentStatus::Posted->value)
            ->whereNull('reversed_at')
            ->with('allocations')
            ->get();

        $posted = 0.0;
        $unallocated = 0.0;

        foreach ($payments as $payment) {
            $amountValue = $payment->amount;
            $allocatedValue = $payment->allocations->sum('amount');
            $amount = (float) $amountValue;
            $allocated = is_numeric($allocatedValue) ? (float) $allocatedValue : 0.0;
            $posted += $amount;
            $unallocated += max(0.0, round($amount - $allocated, 2));
        }

        return [$posted, $unallocated];
    }

    /** @param Collection<int, Invoice> $invoices */
    private function sum(Collection $invoices, string $column): float
    {
        return (float) $invoices->sum(function (Invoice $invoice) use ($column): float {
            $value = $invoice->getAttribute($column);

            return is_numeric($value) ? (float) $value : 0.0;
        });
    }
}
