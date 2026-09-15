<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketEquipmentSource;
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
        return $schema
            ->components([
                Section::make('Ticket')
                    ->schema([
                        TextEntry::make('ticket_number')->label('Ticket number')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('priority')->badge(),
                        TextEntry::make('title')->size(TextSize::Large)->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Customer & Assignment')
                    ->schema([
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('assignedEmployee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                        TextEntry::make('triagedBy.name')->label('Triaged by')->placeholder('Not triaged'),
                        TextEntry::make('triaged_at')->label('Triaged at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Equipment')
                    ->schema([
                        TextEntry::make('equipment_source')->label('Equipment source')->badge()->placeholder('Not triaged'),
                        TextEntry::make('service_path')->label('Service path')->badge()->placeholder('Not triaged'),
                        TextEntry::make('serializedInventoryUnit.productVariant.name')->label('Product')->placeholder('—')
                            ->visible(fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                        TextEntry::make('serializedInventoryUnit.serial_number')->label('Serial number')->placeholder('—')
                            ->visible(fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::SoldByUs),
                        TextEntry::make('external_equipment_name')->label('External equipment')->placeholder('—')
                            ->visible(fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                        TextEntry::make('external_equipment_model')->label('Model')->placeholder('—')
                            ->visible(fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                        TextEntry::make('external_serial_number')->label('Serial number')->placeholder('—')
                            ->visible(fn (Ticket $record): bool => $record->equipment_source === TicketEquipmentSource::External),
                    ])
                    ->columns(2)
                    ->visible(fn (Ticket $record): bool => $record->triaged_at !== null),
                Section::make('Warranty')
                    ->schema([
                        TextEntry::make('warranty_status')->label('Warranty')->badge()->placeholder('Not checked'),
                        TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn (Ticket $record): bool => $record->triaged_at !== null),
                Section::make('SLA')
                    ->schema([
                        TextEntry::make('sla_state')
                            ->label('SLA state')
                            ->state(fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                            ->badge()
                            ->color(fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                        TextEntry::make('live_at')->label('Live since')->dateTime()->placeholder('Not started'),
                        TextEntry::make('response_due_at')->label('First response due')->dateTime()->placeholder('Not started'),
                        TextEntry::make('resolution_due_at')->label('Resolution due')->dateTime()->placeholder('Not started'),
                        TextEntry::make('waiting_customer_accumulated_seconds')
                            ->label('Paused duration')
                            ->formatStateUsing(static fn (mixed $state): string => self::formatDuration(is_numeric($state) ? (int) $state : 0)),
                    ])
                    ->columns(2),
                Section::make('Payment')
                    ->schema([
                        TextEntry::make('payment_summary')
                            ->label('Payment')
                            ->state(static fn (Ticket $record): string => self::paymentSummary($record))
                            ->badge(),
                        TextEntry::make('paymentLink.payment_method_reference')->label('Payment reference')->placeholder('—'),
                        TextEntry::make('charge_waived_reason')->label('Waiver reason')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Resolution')
                    ->schema([
                        TextEntry::make('resolution_summary')->label('Resolution summary')->placeholder('Not resolved')->columnSpanFull(),
                    ])
                    ->visible(fn (Ticket $record): bool => $record->resolution_summary !== null),
            ]);
    }

    private static function paymentSummary(Ticket $ticket): string
    {
        if ($ticket->charge_waived_reason !== null) {
            return 'Waived';
        }

        $link = $ticket->paymentLink;

        if ($link === null) {
            return 'Not required';
        }

        return sprintf('%s — %s %s', str($link->status->value)->headline()->toString(), $link->amount, $link->currency);
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
