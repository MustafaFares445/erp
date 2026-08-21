<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Schemas;

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
                Section::make()
                    ->schema([
                        TextEntry::make('ticket_number')->label('Ticket number')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('priority')->badge(),
                        TextEntry::make('type'),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('assignedEmployee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                        TextEntry::make('title')->size(TextSize::Large)->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull(),
                        // FR-017: the continuation link, visible on the new ticket; recorded at
                        // create time only (TicketForm disables the field on edit).
                        TextEntry::make('continuedFromTicket.ticket_number')
                            ->label('Continues ticket')
                            ->url(fn (Ticket $record): ?string => $record->continued_from_ticket_id === null
                                ? null
                                : TicketResource::getUrl('view', ['record' => $record->continued_from_ticket_id]))
                            ->visible(fn (Ticket $record): bool => $record->continued_from_ticket_id !== null),
                    ])
                    ->columns(2),
                // FR-060: the link must be visible from both records — MaintenanceRecordInfolist
                // already shows the source ticket; this is the reverse direction.
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
