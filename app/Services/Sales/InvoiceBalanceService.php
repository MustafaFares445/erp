<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceFinancialStatus;
use App\Enums\OrderPaymentStatus;
use App\Models\Invoice;
use App\Models\Order;

final readonly class InvoiceBalanceService
{
    /**
     * The human-facing financial status shown on the Invoices List/View
     * pages. Built on top of {@see self::status()} rather than re-deriving
     * the paid/partial/credited math, and layers overdue-ness on top since
     * {@see Invoice::isOverdue()} already implies an outstanding balance.
     */
    public function financialStatus(Invoice $invoice): InvoiceFinancialStatus
    {
        if ($invoice->isOverdue()) {
            return InvoiceFinancialStatus::Overdue;
        }

        return match ($this->status($invoice)) {
            'draft' => InvoiceFinancialStatus::NotPayableYet,
            'issued', 'sent' => InvoiceFinancialStatus::Unpaid,
            'partially_paid' => InvoiceFinancialStatus::PartiallyPaid,
            'paid' => InvoiceFinancialStatus::Paid,
            'credited' => InvoiceFinancialStatus::Credited,
            default => InvoiceFinancialStatus::Unpaid,
        };
    }

    public function status(Invoice $invoice): string
    {
        if (! $invoice->isIssued()) {
            return 'draft';
        }

        $credited = (float) $invoice->credited_amount;
        $total = (float) $invoice->total_amount;
        $claim = max(0.0, $total - $credited);
        $paid = (float) $invoice->amount_paid;

        if ($claim <= 0.00001) {
            return 'credited';
        }

        if ($paid + 0.00001 >= $claim) {
            // The remaining claim, after any credit note, was collected in
            // full. A credit note narrowed the claim below the invoiced
            // total, so it stays the more informative label even though the
            // narrowed claim itself was paid.
            return $credited > 0.0 ? 'credited' : 'paid';
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
            $invoiceClaimMinor = $invoice->receivableClaimMinor();
            $invoiceOutstandingMinor = min($invoiceClaimMinor, $invoice->outstandingMinor());

            $claimMinor += $invoiceClaimMinor;
            $coveredMinor += max(0, $invoiceClaimMinor - $invoiceOutstandingMinor);
        }

        $status = $claimMinor === 0 || $coveredMinor >= $claimMinor
            ? OrderPaymentStatus::Paid
            : ($coveredMinor > 0 ? OrderPaymentStatus::PartiallyPaid : OrderPaymentStatus::Unpaid);

        $order->forceFill(['payment_status' => $status])->save();
    }
}
