<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\UserType;
use App\Events\CampaignCompleted;
use App\Events\InventoryReservationExpired;
use App\Events\InvoiceIssued;
use App\Events\LeadConverted;
use App\Events\PaymentReceived;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Events\SlaAtRisk;
use App\Events\StockLow;
use App\Events\TaskAssigned;
use App\Events\TicketUpdated;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PlanTask;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class SendBusinessNotification
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof CampaignCompleted => $this->campaignCompleted($event),
            $event instanceof InvoiceIssued => $this->invoiceIssued($event->invoice),
            $event instanceof LeadConverted => $this->leadConverted($event->lead, $event->customer),
            $event instanceof PaymentReceived => $this->paymentReceived($event->payment),
            $event instanceof QuotationDecided => $this->quotationDecided($event->quotation),
            $event instanceof QuotationExpired => $this->quotationExpired($event->quotation),
            $event instanceof SlaAtRisk => $this->slaAtRisk($event->ticket, $event->kind),
            $event instanceof StockLow => $this->stockLow($event->stock),
            $event instanceof TaskAssigned => $this->taskAssigned($event->task),
            $event instanceof TicketUpdated => $this->ticketUpdated($event->ticket),
            $event instanceof InventoryReservationExpired => $this->reservationExpired($event),
            default => null,
        };
    }

    private function leadConverted(Lead $lead, CustomerProfile $customer): void
    {
        $recipient = $lead->assignee ?? $lead->creator;

        if (! $recipient instanceof User) {
            return;
        }

        $variables = [
            'lead_name' => $lead->displayName(),
            'customer_name' => (string) ($customer->company_name ?? $customer->customer_code),
        ];

        $this->dispatcher->dispatch($recipient, NotificationEventKey::LeadConverted, $variables, $lead, NotificationChannel::Database);
        $this->dispatcher->dispatch($recipient, NotificationEventKey::LeadConverted, $variables, $lead, NotificationChannel::Mail);
    }

    private function campaignCompleted(CampaignCompleted $event): void
    {
        $campaign = $event->campaign;
        $recipient = $campaign->creator;

        if (! $recipient instanceof User) {
            return;
        }

        $variables = [
            'campaign_name' => (string) $campaign->name,
            'sent_count' => (string) $event->sentCount,
            'failed_count' => (string) $event->failedCount,
        ];

        $this->dispatcher->dispatch($recipient, NotificationEventKey::CampaignCompleted, $variables, $campaign, NotificationChannel::Database);
        $this->dispatcher->dispatch($recipient, NotificationEventKey::CampaignCompleted, $variables, $campaign, NotificationChannel::Mail);
    }

    private function invoiceIssued(Invoice $invoice): void
    {
        $recipient = $invoice->customer?->user;
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::InvoiceIssued, [
                'invoice_number' => (string) $invoice->invoice_number,
                'total_amount' => number_format((float) $invoice->total_amount, 2, '.', ''),
            ], $invoice, NotificationChannel::Database);
        }
    }

    private function paymentReceived(Payment $payment): void
    {
        $recipient = $payment->customer?->user ?? $payment->customer;
        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }
        $variables = [
            'payment_number' => (string) $payment->payment_number,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => (string) $payment->currency,
        ];
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::PaymentReceived, $variables, $payment, NotificationChannel::Database);
        }
        $this->dispatcher->dispatch($recipient, NotificationEventKey::PaymentReceived, $variables, $payment, NotificationChannel::Mail);
    }

    private function quotationDecided(Quotation $quotation): void
    {
        $recipient = $quotation->employee?->user;
        $variables = ['quotation_number' => (string) $quotation->quotation_number, 'status' => $quotation->status->value];
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::QuotationDecided, $variables, $quotation, NotificationChannel::Database);

            return;
        }
        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch($admin, NotificationEventKey::QuotationDecided, $variables, $quotation, NotificationChannel::Database);
        }
    }

    private function quotationExpired(Quotation $quotation): void
    {
        $recipient = $quotation->employee?->user;
        $variables = ['quotation_number' => (string) $quotation->quotation_number];
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::QuotationExpired, $variables, $quotation, NotificationChannel::Database);

            return;
        }
        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch($admin, NotificationEventKey::QuotationExpired, $variables, $quotation, NotificationChannel::Database);
        }
    }

    private function taskAssigned(PlanTask $task): void
    {
        $recipient = $task->salesPlan?->employee?->user;
        if (! $recipient instanceof User) {
            return;
        }
        $variables = ['task_title' => (string) $task->title, 'due_at' => (string) $task->due_at?->toDateString()];
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TaskAssigned, $variables, $task, NotificationChannel::Database);
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TaskAssigned, $variables, $task, NotificationChannel::Mail);
    }

    private function ticketUpdated(Ticket $ticket): void
    {
        $recipient = $ticket->customer?->user ?? $ticket->customer;
        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }
        $variables = ['ticket_number' => (string) $ticket->ticket_number, 'status' => $ticket->status->value];
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::TicketUpdated, $variables, $ticket, NotificationChannel::Database);
        }
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TicketUpdated, $variables, $ticket, NotificationChannel::Mail);
    }

    private function slaAtRisk(Ticket $ticket, string $kind): void
    {
        $variables = ['ticket_number' => (string) $ticket->ticket_number, 'sla_kind' => $kind];
        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch($admin, NotificationEventKey::SlaAtRisk, $variables, $ticket, NotificationChannel::Database);
            $this->dispatcher->dispatch($admin, NotificationEventKey::SlaAtRisk, $variables, $ticket, NotificationChannel::Mail);
        }
    }

    private function stockLow(InventoryStock $stock): void
    {
        $variables = ['stock_id' => (string) $stock->getKey(), 'available_quantity' => (string) $stock->available_quantity];
        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch($admin, NotificationEventKey::StockLow, $variables, $stock, NotificationChannel::Database);
            $this->dispatcher->dispatch($admin, NotificationEventKey::StockLow, $variables, $stock, NotificationChannel::Mail);
        }
    }

    private function reservationExpired(InventoryReservationExpired $event): void
    {
        $reservation = $event->reservation;
        $source = $event->sourceDocument;
        $variables = ['source_reference' => $this->documentReference($source), 'quantity' => (string) $reservation->base_quantity];
        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatch($admin, NotificationEventKey::InventoryReservationExpired, $variables, $source ?? $reservation, NotificationChannel::Database);
            $this->dispatcher->dispatch($admin, NotificationEventKey::InventoryReservationExpired, $variables, $source ?? $reservation, NotificationChannel::Mail);
        }
    }

    /** @return Collection<int, User> */
    private function admins(): Collection
    {
        return User::query()->where('user_type', UserType::Admin->value)->orderBy('id')->get();
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
