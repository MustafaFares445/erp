<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Models\DepositApplicationIssue;
use App\Models\Invoice;
use App\Support\MoneyFormatter;

/**
 * A pure decision table deriving the human-readable "next action" for an
 * Invoice, mirroring the shape of {@see OrderNextActionResolver} — simpler
 * here since every fact needed already lives on the Invoice model, so no
 * facts-bag/workflow-service ceremony is required.
 */
final class InvoiceNextActionResolver
{
    public function resolve(Invoice $invoice): string
    {
        return match (true) {
            $invoice->isDraft() => 'Issue invoice',
            $invoice->status === InvoiceStatus::Cancelled => 'No action required',
            $invoice->status === InvoiceStatus::WrittenOff => 'No collection required',
            $this->hasUnresolvedDepositIssue($invoice) => 'Retry deposit application',
            $invoice->outstandingMinor() <= 0 => 'No action required',
            $invoice->isOverdue() => 'Follow up: collect '.MoneyFormatter::format($invoice->outstandingMinor()),
            default => 'Collect '.MoneyFormatter::format($invoice->outstandingMinor()),
        };
    }

    private function hasUnresolvedDepositIssue(Invoice $invoice): bool
    {
        if ($invoice->relationLoaded('depositApplicationIssues')) {
            return $invoice->depositApplicationIssues->contains(fn (DepositApplicationIssue $issue): bool => $issue->resolved_at === null);
        }

        return $invoice->depositApplicationIssues()->whereNull('resolved_at')->exists();
    }
}
