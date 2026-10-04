<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\PaymentTransactionStatus;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Components\WorkflowStepper;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\PaymentTransaction;
use App\Models\Ticket;
use App\Services\Support\TicketSlaStateResolver;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

final class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Support workspace'))
                ->description(__('Status, next action and customer impact at a glance.'))
                ->schema([
                    WorkflowStepper::make('status')
                        ->hiddenLabel()
                        ->steps([
                            TicketStatus::Pending->value => TicketStatus::Pending->label(),
                            TicketStatus::PendingPayment->value => TicketStatus::PendingPayment->label(),
                            TicketStatus::Live->value => TicketStatus::Live->label(),
                            TicketStatus::Assigned->value => TicketStatus::Assigned->label(),
                            TicketStatus::InProgress->value => TicketStatus::InProgress->label(),
                            TicketStatus::WaitingCustomer->value => TicketStatus::WaitingCustomer->label(),
                            TicketStatus::Resolved->value => TicketStatus::Resolved->label(),
                            TicketStatus::Closed->value => TicketStatus::Closed->label(),
                            TicketStatus::Cancelled->value => TicketStatus::Cancelled->label(),
                        ])
                        ->terminalKeys([TicketStatus::Cancelled->value])
                        ->columnSpanFull(),
                    TextEntry::make('ticket_number')->label(__('Ticket'))->badge(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (TicketStatus $state): string => $state->label())
                        ->color(static fn (TicketStatus $state): string => $state->color()),
                    TextEntry::make('next_action')
                        ->label(__('Next action'))
                        ->state(static fn (Ticket $record): string => self::nextAction($record))
                        ->badge()
                        ->color('primary'),
                    TextEntry::make('priority')->label(__('Priority'))->badge(),
                    TextEntry::make('customer_impact')
                        ->label(__('Customer impact'))
                        ->badge()
                        ->placeholder(__('Not reported'))
                        ->formatStateUsing(static fn (TicketCustomerImpact $state): string => $state->label())
                        ->color(static fn (TicketCustomerImpact $state): string => $state->color()),
                    TextEntry::make('assignedEmployee.user.name')->label(__('Assigned to'))->placeholder(__('Unassigned')),
                    TextEntry::make('title')->size(TextSize::Large)->columnSpanFull(),
                    TextEntry::make('description')->columnSpanFull(),
                    TextEntry::make('continuedFromTicket.ticket_number')
                        ->label(__('Continues ticket'))
                        ->url(static fn (Ticket $record): ?string => $record->continued_from_ticket_id === null
                            ? null
                            : TicketResource::getUrl('view', ['record' => $record->continued_from_ticket_id]))
                        ->visible(static fn (Ticket $record): bool => $record->continued_from_ticket_id !== null),
                ])
                ->columns(3),
            Section::make(__('Equipment & routing'))
                ->description(__('Triage identifies the exact asset and chooses how support continues.'))
                ->visible(static fn (Ticket $record): bool => $record->triaged_at !== null)
                ->schema([
                    TextEntry::make('equipment_source')->label(__('Equipment source'))->badge(),
                    TextEntry::make('service_path')
                        ->label(__('Service path'))
                        ->badge()
                        ->formatStateUsing(static fn (TicketServicePath $state): string => match ($state) {
                            TicketServicePath::RemoteSupport => __('Remote support'),
                            TicketServicePath::Maintenance => __('Workshop / maintenance'),
                            TicketServicePath::OnSiteVisit => __('On-site visit'),
                        }),
                    TextEntry::make('serializedInventoryUnit.productVariant.name')
                        ->label(__('Product'))
                        ->placeholder(__('—'))
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                    TextEntry::make('serializedInventoryUnit.serial_number')
                        ->label(__('Serial number'))
                        ->placeholder(__('—'))
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                    TextEntry::make('external_equipment_name')
                        ->label(__('External equipment'))
                        ->placeholder(__('—'))
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('external_equipment_model')
                        ->label(__('Model'))
                        ->placeholder(__('—'))
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('external_serial_number')
                        ->label(__('Serial number'))
                        ->placeholder(__('—'))
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('triagedBy.name')->label(__('Triaged by'))->placeholder(__('—')),
                    TextEntry::make('triaged_at')->label(__('Triaged at'))->dateTime()->placeholder(__('—')),
                ])
                ->columns(3),
            Section::make(__('Warranty eligibility'))
                ->description(__('Eligibility means the asset may submit a warranty claim. Repair coverage is confirmed later after diagnosis.'))
                ->visible(static fn (Ticket $record): bool => $record->triaged_at !== null)
                ->schema([
                    TextEntry::make('warranty_status')
                        ->label(__('Eligibility'))
                        ->badge()
                        ->placeholder(__('Not checked'))
                        ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                        ->color(static fn (WarrantyStatus $state): string => $state->color()),
                    TextEntry::make('warranty_expiry_date')->label(__('Warranty expiry'))->date()->placeholder(__('—')),
                    TextEntry::make('warranty_message')
                        ->label(__('Meaning'))
                        ->state(static fn (Ticket $record): string => self::warrantyMessage($record))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Service level'))
                ->description(__('First response starts at ticket intake. Resolution starts when technical work becomes live and pauses while waiting on the customer.'))
                ->schema([
                    TextEntry::make('sla_state')
                        ->label(__('SLA state'))
                        ->state(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                        ->badge()
                        ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                    TextEntry::make('first_response')
                        ->label(__('First response'))
                        ->state(static fn (Ticket $record): string => self::firstResponseState($record)),
                    TextEntry::make('response_due_at')->label(__('First response due'))->dateTime()->placeholder(__('Not available')),
                    TextEntry::make('resolution_due_at')->label(__('Resolution due'))->dateTime()->placeholder(__('Not started')),
                    TextEntry::make('resolution_pause')
                        ->label(__('Resolution clock'))
                        ->state(static fn (Ticket $record): string => $record->waiting_customer_since !== null
                            ? __('Paused — waiting for customer')
                            : ($record->live_at === null ? __('Not started') : __('Running'))),
                    TextEntry::make('waiting_customer_accumulated_seconds')
                        ->label(__('Total paused time'))
                        ->formatStateUsing(static fn (mixed $state): string => self::formatDuration(is_numeric($state) ? (int) $state : 0)),
                ])
                ->columns(3),
            Section::make(__('Diagnostic fee'))
                ->description(__('This payment is for diagnosis/support intake only. Final repair charges are decided after technical diagnosis.'))
                ->visible(static fn (Ticket $record): bool => $record->diagnostic_fee_required)
                ->schema([
                    TextEntry::make('diagnostic_fee_amount')->label(__('Fee'))->money(fn (Ticket $record): string => $record->diagnostic_fee_currency ?? 'AED'),
                    TextEntry::make('payment_summary')
                        ->label(__('Payment status'))
                        ->state(static fn (Ticket $record): string => self::paymentSummary($record))
                        ->badge(),
                    TextEntry::make('paymentLink.payment_method_reference')->label(__('Payment reference'))->placeholder(__('—')),
                    TextEntry::make('provider_status')
                        ->label(__('Provider status'))
                        ->badge()
                        ->placeholder(__('No provider transaction'))
                        ->state(static fn (Ticket $record): ?PaymentTransactionStatus => self::providerTransaction($record)?->status)
                        ->formatStateUsing(static fn (?PaymentTransactionStatus $state): string => $state?->label() ?? '—')
                        ->color(static fn (?PaymentTransactionStatus $state): ?string => $state?->color()),
                ])
                ->columns(2),
            Section::make(__('Resolution'))
                ->schema([
                    TextEntry::make('resolution_summary')
                        ->label(__('Resolution summary'))
                        ->placeholder(__('Not resolved'))
                        ->columnSpanFull(),
                ])
                ->visible(static fn (Ticket $record): bool => $record->resolution_summary !== null),
        ]);
    }

    private static function nextAction(Ticket $ticket): string
    {
        return match ($ticket->status) {
            TicketStatus::Pending => __('Triage equipment & route'),
            TicketStatus::PendingPayment => __('Collect diagnostic fee'),
            TicketStatus::Live => __('Assign support owner'),
            TicketStatus::Assigned => __('Start support work'),
            TicketStatus::InProgress => in_array($ticket->service_path, [TicketServicePath::Maintenance, TicketServicePath::OnSiteVisit], true)
                ? __('Continue support / raise maintenance job')
                : __('Continue remote support'),
            TicketStatus::WaitingCustomer => __('Waiting for customer response'),
            TicketStatus::Resolved => __('Review and close'),
            TicketStatus::Closed => __('Complete'),
            TicketStatus::Cancelled => __('Cancelled'),
        };
    }

    private static function warrantyMessage(Ticket $ticket): string
    {
        $expiry = $ticket->warranty_expiry_date?->toDateString();

        return match ($ticket->warranty_status) {
            WarrantyStatus::Covered => $expiry !== null
                ? __('Warranty is active until :date. This does not automatically make the repair free.', ['date' => $expiry])
                : __('Warranty is active. This does not automatically make the repair free.'),
            WarrantyStatus::Expired => $expiry !== null
                ? __('Seller warranty expired on :date. Other coverage sources may still apply after diagnosis.', ['date' => $expiry])
                : __('Seller warranty expired. Other coverage sources may still apply after diagnosis.'),
            WarrantyStatus::NotCovered => __('No seller warranty is configured for this equipment.'),
            WarrantyStatus::NotApplicable => __('Seller warranty is not applicable to this external equipment.'),
            WarrantyStatus::Unknown => __('Warranty information requires verification.'),
            null => __('Warranty has not been checked yet.'),
        };
    }

    private static function firstResponseState(Ticket $ticket): string
    {
        if ($ticket->first_response_at !== null) {
            return $ticket->response_breached ? __('Responded — SLA breached') : __('Responded');
        }

        if ($ticket->response_breached) {
            return __('Overdue');
        }

        return __('Awaiting first response');
    }

    private static function paymentSummary(Ticket $ticket): string
    {
        $link = $ticket->paymentLink;

        if ($link === null) {
            return __('Not required');
        }

        return sprintf('%s — %s %s', $link->status->label(), $link->amount, $link->currency);
    }

    private static function providerTransaction(Ticket $ticket): ?PaymentTransaction
    {
        $link = $ticket->paymentLink;

        if ($link === null) {
            return null;
        }

        $transaction = $link->providerTransaction;

        return $transaction instanceof PaymentTransaction ? $transaction : null;
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dm', $minutes);
    }
}
