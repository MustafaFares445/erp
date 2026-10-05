<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccountingPermission;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InventoryPermission;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Enums\UserType;
use App\Events\CampaignCompleted;
use App\Events\EquipmentCalibrationMilestone;
use App\Events\EquipmentInstallationMilestone;
use App\Events\InventoryReservationExpired;
use App\Events\InvoiceIssued;
use App\Events\LeadConverted;
use App\Events\MaintenanceRecordBilled;
use App\Events\PaymentReceived;
use App\Events\PurchaseOrderAccepted;
use App\Events\PurchaseOrderReceived;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Events\SlaAtRisk;
use App\Events\StockLow;
use App\Events\SupplierCommitmentRecorded;
use App\Events\SupportContinuityMilestone;
use App\Events\SupportQualityMilestone;
use App\Events\TaskAssigned;
use App\Events\TicketClosed;
use App\Events\TicketUpdated;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentLoan;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LotQualityAlert;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\Payment;
use App\Models\PlanTask;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class SendBusinessNotification
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof CampaignCompleted => $this->campaignCompleted($event),
            $event instanceof EquipmentCalibrationMilestone => $this->calibrationMilestone($event),
            $event instanceof EquipmentInstallationMilestone => $this->installationMilestone($event),
            $event instanceof SupportContinuityMilestone => $this->continuityMilestone($event),
            $event instanceof SupportQualityMilestone => $this->qualityMilestone($event),
            $event instanceof InvoiceIssued => $this->invoiceIssued($event->invoice),
            $event instanceof LeadConverted => $this->leadConverted($event->lead, $event->customer),
            $event instanceof MaintenanceRecordBilled => $this->maintenanceRecordBilled($event->record),
            $event instanceof PaymentReceived => $this->paymentReceived($event->payment),
            $event instanceof PurchaseOrderAccepted => $this->purchaseOrderAccepted($event),
            $event instanceof PurchaseOrderReceived => $this->purchaseOrderReceived($event),
            $event instanceof SupplierCommitmentRecorded => $this->supplierCommitmentRecorded($event),
            $event instanceof QuotationDecided => $this->quotationDecided($event->quotation),
            $event instanceof QuotationExpired => $this->quotationExpired($event->quotation),
            $event instanceof SlaAtRisk => $this->slaAtRisk($event->ticket, $event->kind),
            $event instanceof StockLow => $this->stockLow($event->stock),
            $event instanceof TaskAssigned => $this->taskAssigned($event->task),
            $event instanceof TicketClosed => $this->ticketClosed($event->ticket),
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
        $recipient = $payment->customer->user ?? $payment->customer;
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

    private function purchaseOrderAccepted(PurchaseOrderAccepted $event): void
    {
        $order = $event->purchaseOrder->loadMissing('supplier');
        $orderVariables = [
            'purchase_order_number' => (string) $order->purchase_order_number,
        ];
        $requiresConfirmation = $order->supplier_confirmation_required
            ?? (bool) $order->supplier->requires_confirmation;

        if (! $requiresConfirmation) {
            foreach ($this->usersWithPermission(InventoryPermission::WarehouseManage->value) as $recipient) {
                $this->dispatcher->dispatch(
                    $recipient,
                    NotificationEventKey::PurchaseOrderReadyForAllocation,
                    $orderVariables,
                    $order,
                    NotificationChannel::Database,
                );
            }
        }

        $billVariables = [
            ...$orderVariables,
            'bill_number' => (string) $event->bill->bill_number,
        ];

        foreach ($this->usersWithPermission(AccountingPermission::BillManage->value) as $recipient) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::PurchaseOrderDraftBillReady,
                $billVariables,
                $event->bill,
                NotificationChannel::Database,
            );
        }
    }

    private function supplierCommitmentRecorded(SupplierCommitmentRecorded $event): void
    {
        $confirmation = $event->confirmation->loadMissing('items');
        $confirmedTotal = $confirmation->items->sum('confirmed_base_quantity');
        $backorderedTotal = $confirmation->items->sum('backordered_base_quantity');
        $confirmed = is_numeric($confirmedTotal) ? (float) $confirmedTotal : 0.0;
        $backordered = is_numeric($backorderedTotal) ? (float) $backorderedTotal : 0.0;
        $variables = [
            'purchase_order_number' => (string) $event->purchaseOrder->purchase_order_number,
            'confirmed_quantity' => number_format($confirmed, 6, '.', ''),
            'backordered_quantity' => number_format($backordered, 6, '.', ''),
        ];

        if ($confirmed > 0.000001) {
            foreach ($this->usersWithPermission(InventoryPermission::WarehouseManage->value) as $recipient) {
                $this->dispatcher->dispatch(
                    $recipient,
                    NotificationEventKey::PurchaseOrderReadyForAllocation,
                    $variables,
                    $event->purchaseOrder,
                    NotificationChannel::Database,
                );
            }
        }

        $exceptionEvent = match (true) {
            $confirmation->confirmation_status === SupplierConfirmationStatus::Rejected => NotificationEventKey::SupplierCommitmentRejected,
            $backordered > 0.000001 => NotificationEventKey::SupplierCommitmentBackordered,
            default => null,
        };

        if ($exceptionEvent !== null) {
            foreach ($this->usersWithPermission(PurchasePermission::ConfirmationRecord->value) as $recipient) {
                $this->dispatcher->dispatch(
                    $recipient,
                    $exceptionEvent,
                    $variables,
                    $confirmation,
                    NotificationChannel::Database,
                );
            }
        }
    }

    private function purchaseOrderReceived(PurchaseOrderReceived $event): void
    {
        $variables = [
            'purchase_order_number' => (string) $event->purchaseOrder->purchase_order_number,
        ];

        foreach ($this->usersWithPermission(AccountingPermission::BillManage->value) as $recipient) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::PurchaseOrderReceivedForAccounting,
                $variables,
                $event->purchaseOrder,
                NotificationChannel::Database,
            );
        }
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
        $variables = ['task_title' => (string) $task->title, 'due_at' => $task->due_at->toDateString()];
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TaskAssigned, $variables, $task, NotificationChannel::Database);
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TaskAssigned, $variables, $task, NotificationChannel::Mail);
    }

    private function ticketUpdated(Ticket $ticket): void
    {
        // The feedback request sent on closure already tells the customer the ticket is closed.
        if ($ticket->status === TicketStatus::Closed && config('support.csat_enabled', false)) {
            return;
        }

        $recipient = $ticket->customer->user ?? $ticket->customer;
        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }
        $variables = ['ticket_number' => (string) $ticket->ticket_number, 'status' => $ticket->status->value];
        if ($recipient instanceof User) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::TicketUpdated, $variables, $ticket, NotificationChannel::Database);
        }
        $this->dispatcher->dispatch($recipient, NotificationEventKey::TicketUpdated, $variables, $ticket, NotificationChannel::Mail);
    }

    private function ticketClosed(Ticket $ticket): void
    {
        if (! config('support.csat_enabled', false)) {
            return;
        }

        $ticket->loadMissing('customer.user');
        $recipient = $ticket->customer->user ?? $ticket->customer;

        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }

        $variables = ['ticket_number' => (string) $ticket->ticket_number];

        if ($recipient instanceof User) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::TicketFeedbackRequested,
                $variables,
                $ticket,
                NotificationChannel::Database,
            );
        }

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::TicketFeedbackRequested,
            $variables,
            $ticket,
            NotificationChannel::Mail,
        );
    }

    /**
     * Notifies the people a milestone concerns, once each: the customer and/or
     * the assigned technician plus support managers, never twice per recipient.
     */
    private function installationMilestone(EquipmentInstallationMilestone $event): void
    {
        if (! (bool) config('support.equipment_installation_enabled', true)) {
            return;
        }

        $record = $event->record->loadMissing(['customer.user', 'serializedInventoryUnit', 'installation.installedBy.user']);
        $installation = $record->installation;
        $key = $event->key;

        $variables = [
            'maintenance_reference' => '#'.$record->id,
            'serial_number' => (string) ($record->serializedInventoryUnit->serial_number ?? $record->serial_number ?? '—'),
            'customer_name' => (string) ($record->customer->company_name ?? '—'),
        ];

        // Templates reject undeclared variables, so each milestone passes only its own set.
        if ($key === NotificationEventKey::InstallationScheduled) {
            $variables['scheduled_at'] = (string) ($event->appointment?->scheduled_start_at?->toDayDateTimeString() ?? '—');
        }

        if (in_array($key, [NotificationEventKey::CommissioningFailed, NotificationEventKey::CustomerAcceptanceRecorded], true)) {
            $variables['reason'] = $this->installationOutcome($installation ?? throw new LogicException('Outcome milestones follow an installation.'));
        }

        $customer = $record->customer->user ?? $record->customer;
        $technician = $key === NotificationEventKey::InstallationScheduled
            ? $event->appointment?->employee?->user
            : $installation?->installedBy?->user;
        $managers = $this->usersWithPermission(SupportPermission::InstallationManage->value);

        $recipients = match ($key) {
            NotificationEventKey::InstallationScheduled => [$customer, $technician],
            NotificationEventKey::CommissioningFailed => [$technician, ...$managers->all()],
            default => [$customer, ...$managers->all()],
        };

        $seen = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
                continue;
            }

            $identity = sprintf('%s:%d', $recipient->getMorphClass(), $recipient->id);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;

            // A customer profile recipient falls back to the dispatcher's own language resolution.
            $locale = $recipient instanceof User ? $recipient->locale : null;

            if ($recipient instanceof User) {
                $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Database, $locale);
            }

            $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Mail, $locale);
        }
    }

    /**
     * Calibration outcomes reach the customer (completed) or the people who can
     * act on them (failed), once each.
     */
    private function calibrationMilestone(EquipmentCalibrationMilestone $event): void
    {
        if (! (bool) config('support.calibration_enabled', true)) {
            return;
        }

        $record = $event->record->loadMissing(['customer.user', 'serializedInventoryUnit', 'calibration.performedBy.user']);
        $calibration = $record->calibration ?? throw new LogicException('Calibration milestones follow a calibration.');
        $key = $event->key;

        $variables = [
            'maintenance_reference' => '#'.$record->id,
            'serial_number' => (string) ($record->serializedInventoryUnit->serial_number ?? $record->serial_number ?? '—'),
            'customer_name' => (string) ($record->customer->company_name ?? '—'),
        ];

        if ($key === NotificationEventKey::CalibrationFailed) {
            $variables['reason'] = (string) ($calibration->failure_reason ?? '—');
        } else {
            $variables['result'] = (string) ($calibration->result?->label() ?? '—');
        }

        $customer = $record->customer->user ?? $record->customer;
        $technician = $calibration->performedBy?->user;
        $managers = $this->usersWithPermission(SupportPermission::CalibrationManage->value);

        $recipients = $key === NotificationEventKey::CalibrationFailed
            ? [$technician, ...$managers->all()]
            : [$customer, ...$managers->all()];

        $seen = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
                continue;
            }

            $identity = sprintf('%s:%d', $recipient->getMorphClass(), $recipient->id);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $locale = $recipient instanceof User ? $recipient->locale : null;

            if ($recipient instanceof User) {
                $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Database, $locale);
            }

            $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Mail, $locale);
        }
    }

    /**
     * Loaner and supplier-repair milestones. Overdue loaners reach the customer
     * and loan managers; RMA changes reach RMA managers, and a unit coming back
     * from a supplier also reaches the inventory staff who receive it.
     */
    private function continuityMilestone(SupportContinuityMilestone $event): void
    {
        $key = $event->key;
        $record = $event->record->loadMissing(['customer.user', 'serializedInventoryUnit']);
        $serial = (string) ($record->serializedInventoryUnit->serial_number ?? $record->serial_number ?? '—');
        $reference = '#'.$record->id;

        if ($key === NotificationEventKey::LoanerOverdue) {
            if (! (bool) config('support.loaner_equipment_enabled', false)) {
                return;
            }

            $loan = EquipmentLoan::query()->with('loanerUnit')->findOrFail($event->subjectId);
            $variables = [
                'maintenance_reference' => $reference,
                'serial_number' => $serial,
                'customer_name' => (string) ($record->customer->company_name ?? '—'),
                'loaner_serial' => (string) ($loan->loanerUnit->serial_number ?? '—'),
                'expected_return_at' => (string) ($loan->expected_return_at?->toDayDateTimeString() ?? '—'),
            ];
            $recipients = [$record->customer->user ?? $record->customer, ...$this->usersWithPermission(SupportPermission::LoanManage->value)->all()];
        } else {
            if (! (bool) config('support.external_repair_enabled', false)) {
                return;
            }

            $repair = MaintenanceExternalRepair::query()->with('supplier')->findOrFail($event->subjectId);
            $variables = [
                'maintenance_reference' => $reference,
                'serial_number' => $serial,
                'supplier_name' => (string) ($repair->supplier->name ?? '—'),
                'rma_status' => $repair->status->label(),
            ];
            $recipients = [
                ...$this->usersWithPermission(SupportPermission::RmaManage->value)->all(),
                ...($key === NotificationEventKey::EquipmentReturnedFromSupplier ? $this->usersWithPermission(InventoryPermission::SupplierCustodyManage->value)->all() : []),
            ];
        }

        $seen = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
                continue;
            }

            $identity = sprintf('%s:%d', $recipient->getMorphClass(), $recipient->id);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $locale = $recipient instanceof User ? $recipient->locale : null;

            if ($recipient instanceof User) {
                $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Database, $locale);
            }

            $this->dispatcher->dispatch($recipient, $key, $variables, $record, NotificationChannel::Mail, $locale);
        }
    }

    /**
     * Quality complaints and lot signals reach quality managers; a lot that
     * crosses the complaint threshold also reaches the inventory staff who
     * decide on quarantine (the alert never changes stock itself).
     */
    private function qualityMilestone(SupportQualityMilestone $event): void
    {
        if (! (bool) config('support.product_quality_enabled', false)) {
            return;
        }

        $subject = $event->subject;
        $managers = $this->usersWithPermission(SupportPermission::QualityComplaintManage->value);

        if ($subject instanceof Ticket) {
            $subject->loadMissing(['customer', 'productContexts.productVariant', 'productContexts.inventoryLot']);
            $context = $subject->productContexts->first();
            $productVariant = $context instanceof TicketProductContext ? $context->productVariant : null;
            $inventoryLot = $context instanceof TicketProductContext ? $context->inventoryLot : null;
            $productName = $productVariant instanceof ProductVariant ? (string) $productVariant->name : '—';
            $lotNumber = $inventoryLot instanceof InventoryLot ? (string) $inventoryLot->lot_number : '—';
            $variables = [
                'ticket_number' => (string) $subject->ticket_number,
                'customer_name' => (string) ($subject->customer->company_name ?? '—'),
                'product_name' => $productName,
                'lot_number' => $lotNumber,
            ];
            $recipients = $managers->all();
        } else {
            $product = ProductVariant::query()->whereKey($subject->product_variant_id)->first();
            $openComplaints = LotQualityAlert::query()->where('inventory_lot_id', $subject->id)->value('open_complaints');
            $configuredThreshold = config('support.lot_complaint_threshold', 3);
            $variables = [
                'lot_number' => (string) ($subject->lot_number ?? '—'),
                'product_name' => $product instanceof ProductVariant ? (string) $product->name : '—',
                'open_complaints' => is_numeric($openComplaints) ? (string) $openComplaints : '0',
                'threshold' => is_numeric($configuredThreshold) ? (string) $configuredThreshold : '3',
            ];
            $recipients = [...$managers->all(), ...$this->usersWithPermission(InventoryPermission::ConditionChangeCreate->value)->all()];
        }

        $seen = [];

        foreach ($recipients as $recipient) {
            if (isset($seen[$recipient->id])) {
                continue;
            }

            $seen[$recipient->id] = true;

            $this->dispatcher->dispatch($recipient, $event->key, $variables, $subject, NotificationChannel::Database, $recipient->locale);
            $this->dispatcher->dispatch($recipient, $event->key, $variables, $subject, NotificationChannel::Mail, $recipient->locale);
        }
    }

    private function installationOutcome(EquipmentInstallation $installation): string
    {
        return match ($installation->customer_acceptance_status) {
            CustomerAcceptanceStatus::Accepted => __('Accepted by :name', ['name' => (string) $installation->customer_signatory_name]),
            CustomerAcceptanceStatus::Rejected => __('Rejected: :reason', ['reason' => (string) $installation->customer_rejection_reason]),
            CustomerAcceptanceStatus::Pending => (string) ($installation->commissioning_failure_reason ?? '—'),
        };
    }

    private function maintenanceRecordBilled(MaintenanceRecord $record): void
    {
        $record->loadMissing(['customer.user', 'invoice']);
        $recipient = $record->customer->user ?? $record->customer;

        if (! $recipient instanceof User && ! $recipient instanceof CustomerProfile) {
            return;
        }

        $variables = [
            'maintenance_reference' => '#'.$record->id,
            'invoice_number' => (string) ($record->invoice->invoice_number ?? '—'),
        ];

        if ($recipient instanceof User) {
            $this->dispatcher->dispatch(
                $recipient,
                NotificationEventKey::MaintenanceRecordBilled,
                $variables,
                $record,
                NotificationChannel::Database,
            );
        }

        $this->dispatcher->dispatch(
            $recipient,
            NotificationEventKey::MaintenanceRecordBilled,
            $variables,
            $record,
            NotificationChannel::Mail,
        );
    }

    private function slaAtRisk(Ticket $ticket, string $kind): void
    {
        $ticket->loadMissing(['assignedEmployee.user', 'supportTeam.manager']);
        $variables = ['ticket_number' => (string) $ticket->ticket_number, 'sla_kind' => $kind];

        $recipients = collect([
            $ticket->assignedEmployee?->user,
            $ticket->supportTeam?->manager,
        ])
            ->filter(static fn (mixed $recipient): bool => $recipient instanceof User)
            ->keyBy(static fn (User $recipient): int => $recipient->id);

        if ($recipients->isEmpty()) {
            $recipients = $this->usersWithPermission(SupportPermission::TicketManage->value)
                ->keyBy(static fn (User $recipient): int => $recipient->id);
        }

        foreach ($recipients as $recipient) {
            $this->dispatcher->dispatch($recipient, NotificationEventKey::SlaAtRisk, $variables, $ticket, NotificationChannel::Database);
            $this->dispatcher->dispatch($recipient, NotificationEventKey::SlaAtRisk, $variables, $ticket, NotificationChannel::Mail);
        }
    }

    private function stockLow(InventoryStock $stock): void
    {
        $variables = ['stock_id' => (string) $stock->id, 'available_quantity' => (string) $stock->available_quantity];
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
    private function usersWithPermission(string $permission): Collection
    {
        return User::query()
            ->where(function (Builder $query) use ($permission): void {
                $query->whereHas('permissions', fn (Builder $permissions): Builder => $permissions->where('name', $permission)->where('guard_name', 'web'))
                    ->orWhereHas('roles.permissions', fn (Builder $permissions): Builder => $permissions->where('name', $permission)->where('guard_name', 'web'));
            })
            ->orderBy('id')
            ->get();
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

        $key = $document->getKey();

        return class_basename($document).' #'.(is_int($key) || is_string($key) ? $key : 'unknown');
    }
}
