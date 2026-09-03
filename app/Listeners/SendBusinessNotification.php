<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\UserType;
use App\Events\InventoryReservationExpired;
use App\Events\InvoiceIssued;
use App\Events\PaymentReceived;
use App\Events\QuotationDecided;
use App\Events\TaskAssigned;
use App\Events\TicketUpdated;
use App\Models\CustomerProfile;
use App\Models\InventoryReservation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanTask;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Model;

final readonly class SendBusinessNotification
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof InvoiceIssued => $this->invoiceIssued($event->invoice),
            $event instanceof PaymentReceived => $this->paymentReceived($event->payment),
            $event instanceof QuotationDecided => $this->quotationDecided($event->quotation),
            $event instanceof TaskAssigned => $this->taskAssigned($event->task),
            $event instanceof TicketUpdated => $this->ticketUpdated($event->ticket),
            $event instanceof InventoryReservationExpired => $this->reservationExpired($event),
            default => null,
        };
    }

    private function invoiceIssued(Invoice $invoice): void
    {
        $recipient = $invoice->customer?->user;

        if (! $recipient instanceof User) {
            return;
        }

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::InvoiceIssued,
            [
                'invoice_number' => (string) $invoice->invoice_number,
                'total_amount' => number_format((float) $invoice->total_amount, 2, '.', ''),
            ],
            $invoice,
            NotificationChannel::Database,
        );
    }

    private function paymentReceived(Payment $payment): void
    {
        $recipient = $payment->customer?->user ?? $payment->customer;

        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }

        if ($recipient instanceof User) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::PaymentReceived,
                [
                    'payment_number' => (string) $payment->payment_number,
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'currency' => (string) $payment->currency,
                ],
                $payment,
                NotificationChannel::Database,
            );
        }

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::PaymentReceived,
            [
                'payment_number' => (string) $payment->payment_number,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => (string) $payment->currency,
            ],
            $payment,
            NotificationChannel::Mail,
        );
    }

    private function quotationDecided(Quotation $quotation): void
    {
        $recipient = $quotation->employee?->user;

        if ($recipient instanceof User) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::QuotationDecided,
                [
                    'quotation_number' => (string) $quotation->quotation_number,
                    'status' => $quotation->status->value,
                ],
                $quotation,
                NotificationChannel::Database,
            );

            return;
        }

        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch(
                $admin,
                NotificationEventKey::QuotationDecided,
                [
                    'quotation_number' => (string) $quotation->quotation_number,
                    'status' => $quotation->status->value,
                ],
                $quotation,
                NotificationChannel::Database,
            );
        }
    }

    private function taskAssigned(PlanTask $task): void
    {
        $recipient = $task->salesPlan?->employee?->user;

        if (! $recipient instanceof User) {
            return;
        }

        $variables = [
            'task_title' => (string) $task->title,
            'due_at' => (string) $task->due_at?->toDateString(),
        ];

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::TaskAssigned,
            $variables,
            $task,
            NotificationChannel::Database,
        );
        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::TaskAssigned,
            $variables,
            $task,
            NotificationChannel::Mail,
        );
    }

    private function ticketUpdated(Ticket $ticket): void
    {
        $recipient = $ticket->customer?->user ?? $ticket->customer;

        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }

        $variables = [
            'ticket_number' => (string) $ticket->ticket_number,
            'status' => $ticket->status->value,
        ];

        if ($recipient instanceof User) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::TicketUpdated,
                $variables,
                $ticket,
                NotificationChannel::Database,
            );
        }

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::TicketUpdated,
            $variables,
            $ticket,
            NotificationChannel::Mail,
        );
    }

    private function reservationExpired(InventoryReservationExpired $event): void
    {
        $reservation = $event->reservation;
        $source = $event->sourceDocument;
        $reference = $this->documentReference($source);
        $variables = [
            'source_reference' => $reference,
            'quantity' => (string) $reservation->base_quantity,
        ];

        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch(
                $admin,
                NotificationEventKey::InventoryReservationExpired,
                $variables,
                $source ?? $reservation,
                NotificationChannel::Database,
            );
            $this->dispatcher->dispatch(
                $admin,
                NotificationEventKey::InventoryReservationExpired,
                $variables,
                $source ?? $reservation,
                NotificationChannel::Mail,
            );
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    private function admins(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('user_type', UserType::Admin->value)
            ->orderBy('id')
            ->get();
    }

    private function documentReference(?Model $document): string
    {
        if (! $document instanceof Model) {
            return 'reservation';
        }

        foreach (['order_number', 'quotation_number', 'operation_number', 'purchase_order_number', 'invoice_number'] as $attribute) {
            $value = $document->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($document).' #'.$document->getKey();
    }
}
