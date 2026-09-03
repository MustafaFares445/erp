<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEventKey;
use App\Models\Invoice;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sales\InvoiceBalanceService;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SendInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $invoiceId,
        public int $actorId,
    ) {}

    public function handle(
        InvoiceBalanceService $balances,
        NotificationDispatcher $dispatcher,
    ): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()
            ->with(['customer', 'lines', 'order', 'inventoryOperation'])
            ->findOrFail($this->invoiceId);

        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true)) {
            throw new DomainException('Only an issued or sent invoice can be emailed.');
        }

        $media = $invoice->getFirstMedia('invoice-pdf');

        if ($media === null) {
            throw new DomainException('Generate the invoice PDF before sending it.');
        }

        $email = $invoice->customer?->email;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('The invoice customer needs a valid email address before sending.');
        }

        $recipient = $invoice->customer;

        if (! $recipient instanceof CustomerProfile) {
            throw new DomainException('The invoice customer no longer exists.');
        }

        $delivery = $dispatcher->dispatch(
            $recipient,
            NotificationEventKey::InvoiceIssued,
            [
                'invoice_number' => (string) $invoice->invoice_number,
                'total_amount' => number_format((float) $invoice->total_amount, 2, '.', ''),
            ],
            $invoice,
            NotificationChannel::Mail,
            attachments: [[
                'path' => $media->getPath(),
                'name' => $invoice->invoice_number.'.pdf',
                'mime' => 'application/pdf',
            ]],
            sendNow: true,
        );

        if (! in_array($delivery->status, [
            NotificationDeliveryStatus::Queued,
            NotificationDeliveryStatus::Sent,
        ], true)) {
            throw new DomainException(
                'The invoice notification could not be queued: '.($delivery->error ?? $delivery->status->value),
            );
        }

        DB::transaction(function () use ($invoice, $balances, $email, $delivery): void {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->with('confirmations')
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->sole();

            if ($locked->status === InvoiceStatus::Issued) {
                $locked->assertCanTransitionTo(InvoiceStatus::Sent);
            } elseif ($locked->status !== InvoiceStatus::Sent) {
                throw new DomainException('Only an issued or sent invoice can be emailed.');
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Sent,
                'sent_at' => now(),
                'updated_by' => $this->actorId,
            ])->save();

            $balances->syncInvoice($locked);

            $actor = User::query()->find($this->actorId);
            $activity = activity()->performedOn($locked);

            if ($actor instanceof User) {
                $activity->causedBy($actor);
            }

            $activity
                ->withProperties([
                    'source_channel' => 'mail',
                    'recipient' => $email,
                    'notification_delivery_id' => $delivery->getKey(),
                ])
                ->log('sales.invoice.sent');
        });
    }
}
