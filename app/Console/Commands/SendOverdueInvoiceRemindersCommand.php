<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:overdue-invoices')]
#[Description('Send each 7, 30, and 60 day overdue invoice reminder at most once.')]
final class SendOverdueInvoiceRemindersCommand extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $sent = 0;

        Invoice::query()
            ->whereNotNull('issued_at')
            ->whereNotIn('status', [InvoiceStatus::WrittenOff->value, InvoiceStatus::Cancelled->value])
            ->with(['customer.user', 'paymentTerm', 'writeOffs'])
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use ($dispatcher, &$sent): void {
                foreach ($invoices as $invoice) {
                    if (! $invoice instanceof Invoice) {
                        continue;
                    }
                    if (! $invoice->isOverdue()) {
                        continue;
                    }
                    if ($invoice->due_date === null) {
                        continue;
                    }
                    $recipient = $invoice->customer?->user ?? $invoice->customer;

                    if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
                        continue;
                    }

                    $daysOverdue = (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay());

                    foreach ($this->thresholds() as $days => $event) {
                        if ($daysOverdue < $days) {
                            continue;
                        }
                        if ($this->alreadyAttempted($invoice, $event)) {
                            continue;
                        }
                        $dispatcher->dispatch(
                            $recipient,
                            $event,
                            [
                                'invoice_number' => (string) $invoice->invoice_number,
                                'amount_due' => number_format($invoice->outstandingMinor() / 100, 2, '.', ''),
                                'days_overdue' => $days,
                            ],
                            $invoice,
                            NotificationChannel::Mail,
                        );

                        $sent++;
                    }
                }
            });

        $this->components->info(sprintf('Overdue invoice reminder sweep completed: %d reminder(s) queued.', $sent));

        return self::SUCCESS;
    }

    /** @return array<int, NotificationEventKey> */
    private function thresholds(): array
    {
        return [
            7 => NotificationEventKey::InvoiceOverdue7,
            30 => NotificationEventKey::InvoiceOverdue30,
            60 => NotificationEventKey::InvoiceOverdue60,
        ];
    }

    private function alreadyAttempted(Invoice $invoice, NotificationEventKey $event): bool
    {
        return NotificationDelivery::query()
            ->where('subject_document_type', $invoice->getMorphClass())
            ->where('subject_document_id', $invoice->getKey())
            ->where('template_key', $event->value)
            ->exists();
    }
}
