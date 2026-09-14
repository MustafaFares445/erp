<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketEquipmentSource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use Filament\Infolists\Components\RepeatableEntry;
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
                Section::make('Ticket summary')
                    ->schema([
                        TextEntry::make('ticket_number')->label('Ticket number')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('priority')->badge(),
                        TextEntry::make('type'),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('assignedEmployee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                        TextEntry::make('title')->size(TextSize::Large)->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull(),
                        TextEntry::make('continuedFromTicket.ticket_number')
                            ->label('Continues ticket')
                            ->url(fn (Ticket $record): ?string => $record->continued_from_ticket_id === null
                                ? null
                                : TicketResource::getUrl('view', ['record' => $record->continued_from_ticket_id]))
                            ->visible(fn (Ticket $record): bool => $record->continued_from_ticket_id !== null),
                    ])
                    ->columns(2),
                Section::make('Triage & equipment')
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
                        TextEntry::make('warranty_status')->label('Warranty')->badge()->placeholder('Not checked'),
                        TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                        TextEntry::make('triagedBy.name')->label('Triaged by')->placeholder('—'),
                        TextEntry::make('triaged_at')->label('Triaged at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn (Ticket $record): bool => $record->triaged_at !== null),
                Section::make('SLA & payment')
                    ->schema([
                        TextEntry::make('pending_reason')->label('Blocking reason')->placeholder('—'),
                        TextEntry::make('response_due_at')->label('First response due')->dateTime()->placeholder('Not started'),
                        TextEntry::make('resolution_due_at')->label('Resolution due')->dateTime()->placeholder('Not started'),
                        TextEntry::make('paymentLink.amount')->label('Payment amount')->placeholder('—'),
                        TextEntry::make('paymentLink.currency')->label('Currency')->placeholder('—'),
                        TextEntry::make('paymentLink.status')->label('Payment status')->badge()->placeholder('Not required'),
                        TextEntry::make('paymentLink.payment_method_reference')->label('Payment reference')->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Resolution')
                    ->schema([
                        TextEntry::make('resolution_summary')->label('Resolution summary')->placeholder('Not resolved')->columnSpanFull(),
                    ])
                    ->visible(fn (Ticket $record): bool => $record->resolution_summary !== null),
                Section::make('Maintenance requests raised from this ticket')
                    ->schema([
                        RepeatableEntry::make('maintenanceRecords')
                            ->label('')
                            ->schema([
                                TextEntry::make('status')->badge(),
                                TextEntry::make('warranty_status')->label('Warranty'),
                                TextEntry::make('created_at')->label('Raised')->dateTime(),
                                TextEntry::make('id')
                                    ->label('')
                                    ->formatStateUsing(fn (): string => 'View →')
                                    ->url(fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record->getKey()])),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Ticket $record): bool => $record->maintenanceRecords()->exists()),
            ]);
    }
}
