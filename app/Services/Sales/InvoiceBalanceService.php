<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OrderPaymentStatus;
use App\Models\Invoice;
use App\Models\Order;

final readonly class InvoiceBalanceService
{
    public function status(Invoice $invoice): string
    {
        if (! $invoice->isIssued()) {
            return 'draft';
        }

        $credited = (float) $invoice->credited_amount;
        $total = (float) $invoice->total_amount;

        if ($credited + 0.00001 >= $total) {
            return 'credited';
        }

        $claim = max(0.0, $total - $credited);
        $paid = (float) $invoice->amount_paid;

        if ($claim > 0.0 && $paid + 0.00001 >= $claim) {
            return 'paid';
        }

        if ($paid > 0.0) {
            return 'partially_paid';
        }

        return $invoice->sent_at !== null ? 'sent' : 'issued';
    }

    /**
     * Recompute dependent balance reads without mutating the invoice lifecycle.
     *
     * Payment, credit and receipt evidence are independent axes. Before
     * WP-1.8 this method collapsed them back into invoices.status.
     */
    public function syncInvoice(Invoice $invoice): Invoice
    {
        return $invoice->refresh();
    }

    public function syncOrder(?Order $order): void
    {
        if (! $order instanceof Order) {
            return;
        }

        $invoices = $order->invoices()
            ->whereNotNull('issued_at')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            $order->forceFill(['payment_status' => OrderPaymentStatus::Unpaid])->save();

            return;
        }

        $claimMinor = 0;
        $coveredMinor = 0;

        foreach ($invoices as $invoice) {
            $totalMinor = $this->minor($invoice->total_amount);
            $creditedMinor = min($totalMinor, $this->minor($invoice->credited_amount));
            $invoiceClaimMinor = max(0, $totalMinor - $creditedMinor);
            $paidMinor = min($invoiceClaimMinor, $this->minor($invoice->amount_paid));

            $claimMinor += $invoiceClaimMinor;
            $coveredMinor += $paidMinor;
        }

        $status = $claimMinor === 0 || $coveredMinor >= $claimMinor
            ? OrderPaymentStatus::Paid
            : ($coveredMinor > 0 ? OrderPaymentStatus::PartiallyPaid : OrderPaymentStatus::Unpaid);

        $order->forceFill(['payment_status' => $status])->save();
    }

    private function minor(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
