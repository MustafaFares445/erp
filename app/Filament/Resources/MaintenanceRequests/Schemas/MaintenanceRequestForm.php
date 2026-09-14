<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Models\Ticket;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class MaintenanceRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('ticket_id'),
                Section::make('Maintenance Request')
                    ->description('Ticket-backed requests inherit customer, equipment and warranty from ticket triage. Standalone requests resolve known serials automatically.')
                    ->schema([
                        Placeholder::make('linked_ticket')
                            ->label('Raised from ticket')
                            ->content(static function (Get $get): string {
                                if (! is_numeric($get('ticket_id'))) {
                                    return '—';
                                }

                                $ticket = Ticket::query()->find((int) $get('ticket_id'));

                                return $ticket instanceof Ticket ? $ticket->ticket_number : '—';
                            })
                            ->visible(static fn (Get $get): bool => is_numeric($get('ticket_id'))),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload()
                            ->required(static fn (Get $get): bool => ! is_numeric($get('ticket_id')))
                            ->visible(static fn (Get $get): bool => ! is_numeric($get('ticket_id'))),
                        TextInput::make('serial_number')
                            ->label('Serial number')
                            ->maxLength(255)
                            ->helperText('Known equipment is matched automatically; unmatched serials are treated as external equipment.')
                            ->visible(static fn (Get $get): bool => ! is_numeric($get('ticket_id'))),
                        Placeholder::make('warranty_resolution')
                            ->label('Warranty')
                            ->content(static fn (Get $get): string => is_numeric($get('ticket_id'))
                                ? 'Inherited from the ticket triage decision.'
                                : 'Resolved automatically from the selected customer and serial number.')
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
