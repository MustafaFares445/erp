<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\InvoiceBalanceService;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $invoiceId,
        public int $actorId,
    ) {}

    public function handle(InvoiceBalanceService $balances): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()
            ->with(['customer', 'lines', 'order', 'inventoryOperation'])
            ->findOrFail($this->invoiceId);

        if (! $invoice->isIssued()) {
            throw new DomainException('Only an issued invoice can be sent.');
        }

        $media = $invoice->getFirstMedia('invoice-pdf');

        if ($media === null) {
            throw new DomainException('Generate the invoice PDF before sending it.');
        }

        $email = $invoice->customer?->email;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('The invoice customer needs a valid email address before sending.');
        }

        Mail::to($email)->send(new InvoiceMail($invoice, $media->getPath()));

        DB::transaction(function () use ($invoice, $balances, $email): void {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->with('confirmations')
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->forceFill([
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
                ])
                ->log('sales.invoice.sent');
        });
    }
}
