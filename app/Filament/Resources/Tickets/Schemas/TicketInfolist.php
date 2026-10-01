<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\PaymentTransactionStatus;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
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
            Section::make('Support workspace')
                ->description('Status, next action and customer impact at a glance.')
                ->schema([
                    TextEntry::make('ticket_number')->label('Ticket')->badge(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(static fn (TicketStatus $state): string => $state->label())
                        ->color(static fn (TicketStatus $state): string => $state->color()),
                    TextEntry::make('next_action')
                        ->label('Next action')
                        ->state(static fn (Ticket $record): string => self::nextAction($record))
                        ->badge()
                        ->color('primary'),
                    TextEntry::make('priority')->label('Priority')->badge(),
                    TextEntry::make('customer_impact')
                        ->label('Customer impact')
                        ->badge()
                        ->placeholder('Not reported')
                        ->formatStateUsing(static fn (TicketCustomerImpact $state): string => $state->label())
                        ->color(static fn (TicketCustomerImpact $state): string => $state->color()),
                    TextEntry::make('assignedEmployee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                    TextEntry::make('title')->size(TextSize::Large)->columnSpanFull(),
                    TextEntry::make('description')->columnSpanFull(),
                    TextEntry::make('continuedFromTicket.ticket_number')
                        ->label('Continues ticket')
                        ->url(static fn (Ticket $record): ?string => $record->continued_from_ticket_id === null
                            ? null
                            : TicketResource::getUrl('view', ['record' => $record->continued_from_ticket_id]))
                        ->visible(static fn (Ticket $record): bool => $record->continued_from_ticket_id !== null),
                ])
                ->columns(3),
            Section::make('Equipment & routing')
                ->description('Triage identifies the exact asset and chooses how support continues.')
                ->visible(static fn (Ticket $record): bool => $record->triaged_at !== null)
                ->schema([
                    TextEntry::make('equipment_source')->label('Equipment source')->badge(),
                    TextEntry::make('service_path')
                        ->label('Service path')
                        ->badge()
                        ->formatStateUsing(static fn (TicketServicePath $state): string => match ($state) {
                            TicketServicePath::RemoteSupport => 'Remote support',
                            TicketServicePath::Maintenance => 'Workshop / maintenance',
                            TicketServicePath::OnSiteVisit => 'On-site visit',
                        }),
                    TextEntry::make('serializedInventoryUnit.productVariant.name')
                        ->label('Product')
                        ->placeholder('—')
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                    TextEntry::make('serializedInventoryUnit.serial_number')
                        ->label('Serial number')
                        ->placeholder('—')
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                    TextEntry::make('external_equipment_name')
                        ->label('External equipment')
                        ->placeholder('—')
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('external_equipment_model')
                        ->label('Model')
                        ->placeholder('—')
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('external_serial_number')
                        ->label('Serial number')
                        ->placeholder('—')
                        ->visible(static fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    TextEntry::make('triagedBy.name')->label('Triaged by')->placeholder('—'),
                    TextEntry::make('triaged_at')->label('Triaged at')->dateTime()->placeholder('—'),
                ])
                ->columns(3),
            Section::make('Warranty eligibility')
                ->description('Eligibility means the asset may submit a warranty claim. Repair coverage is confirmed later after diagnosis.')
                ->visible(static fn (Ticket $record): bool => $record->triaged_at !== null)
                ->schema([
                    TextEntry::make('warranty_status')
                        ->label('Eligibility')
                        ->badge()
                        ->placeholder('Not checked')
                        ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                        ->color(static fn (WarrantyStatus $state): string => $state->color()),
                    TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                    TextEntry::make('warranty_message')
                        ->label('Meaning')
                        ->state(static fn (Ticket $record): string => self::warrantyMessage($record))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Service level')
                ->description('First response starts at ticket intake. Resolution starts when technical work becomes live and pauses while waiting on the customer.')
                ->schema([
                    TextEntry::make('sla_state')
                        ->label('SLA state')
                        ->state(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                        ->badge()
                        ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                    TextEntry::make('first_response')
                        ->label('First response')
                        ->state(static fn (Ticket $record): string => self::firstResponseState($record)),
                    TextEntry::make('response_due_at')->label('First response due')->dateTime()->placeholder('Not available'),
                    TextEntry::make('resolution_due_at')->label('Resolution due')->dateTime()->placeholder('Not started'),
                    TextEntry::make('resolution_pause')
                        ->label('Resolution clock')
                        ->state(static fn (Ticket $record): string => $record->waiting_customer_since !== null
                            ? 'Paused — waiting for customer'
                            : ($record->live_at === null ? 'Not started' : 'Running')),
                    TextEntry::make('waiting_customer_accumulated_seconds')
                        ->label('Total paused time')
                        ->formatStateUsing(static fn (mixed $state): string => self::formatDuration(is_numeric($state) ? (int) $state : 0)),
                ])
                ->columns(3),
            Section::make('Diagnostic fee')
                ->description('This payment is for diagnosis/support intake only. Final repair charges are decided after technical diagnosis.')
                ->visible(static fn (Ticket $record): bool => $record->diagnostic_fee_required)
                ->schema([
                    TextEntry::make('diagnostic_fee_amount')->label('Fee')->money(fn (Ticket $record): string => $record->diagnostic_fee_currency ?? 'AED'),
                    TextEntry::make('payment_summary')
                        ->label('Payment status')
                        ->state(static fn (Ticket $record): string => self::paymentSummary($record))
                        ->badge(),
                    TextEntry::make('paymentLink.payment_method_reference')->label('Payment reference')->placeholder('—'),
                    TextEntry::make('provider_status')
                        ->label('Provider status')
                        ->badge()
                        ->placeholder('No provider transaction')
                        ->state(static fn (Ticket $record): ?PaymentTransactionStatus => self::providerTransaction($record)?->status)
                        ->formatStateUsing(static fn (?PaymentTransactionStatus $state): string => $state?->label() ?? '—')
                        ->color(static fn (?PaymentTransactionStatus $state): ?string => $state?->color()),
                ])
                ->columns(2),
            Section::make('Resolution')
                ->schema([
                    TextEntry::make('resolution_summary')
                        ->label('Resolution summary')
                        ->placeholder('Not resolved')
                        ->columnSpanFull(),
                ])
                ->visible(static fn (Ticket $record): bool => $record->resolution_summary !== null),
        ]);
    }

    private static function nextAction(Ticket $ticket): string
    {
        return match ($ticket->status) {
            TicketStatus::Pending => 'Triage equipment & route',
            TicketStatus::PendingPayment => 'Collect diagnostic fee',
            TicketStatus::Live => 'Assign support owner',
            TicketStatus::Assigned => 'Start support work',
            TicketStatus::InProgress => in_array($ticket->service_path, [TicketServicePath::Maintenance, TicketServicePath::OnSiteVisit], true)
                ? 'Continue support / raise maintenance job'
                : 'Continue remote support',
            TicketStatus::WaitingCustomer => 'Waiting for customer response',
            TicketStatus::Resolved => 'Review and close',
            TicketStatus::Closed => 'Complete',
            TicketStatus::Cancelled => 'Cancelled',
        };
    }

    private static function warrantyMessage(Ticket $ticket): string
    {
        $expiry = $ticket->warranty_expiry_date?->toDateString();

        return match ($ticket->warranty_status) {
            WarrantyStatus::Covered => 'Warranty is active'.($expiry !== null ? ' until '.$expiry : '').'. This does not automatically make the repair free.',
            WarrantyStatus::Expired => 'Seller warranty expired'.($expiry !== null ? ' on '.$expiry : '').'. Other coverage sources may still apply after diagnosis.',
            WarrantyStatus::NotCovered => 'No seller warranty is configured for this equipment.',
            WarrantyStatus::NotApplicable => 'Seller warranty is not applicable to this external equipment.',
            WarrantyStatus::Unknown => 'Warranty information requires verification.',
            null => 'Warranty has not been checked yet.',
        };
    }

    private static function firstResponseState(Ticket $ticket): string
    {
        if ($ticket->first_response_at !== null) {
            return $ticket->response_breached ? 'Responded — SLA breached' : 'Responded';
        }

        if ($ticket->response_breached) {
            return 'Overdue';
        }

        return 'Awaiting first response';
    }

    private static function paymentSummary(Ticket $ticket): string
    {
        $link = $ticket->paymentLink;

        if ($link === null) {
            return 'Not required';
        }

        return sprintf('%s — %s %s', str($link->status->value)->headline()->toString(), $link->amount, $link->currency);
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
