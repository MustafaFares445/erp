<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Enums\WarrantyStatus;
use App\Models\Ticket;
use Filament\Forms\Components\DatePicker;
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
                    ->schema([
                        Placeholder::make('linked_ticket')
                            ->label('Raised from ticket')
                            ->content(static function (Get $get): string {
                                // @codeCoverageIgnoreStart
                                // The component's own ->visible() below already requires
                                // ticket_id to be numeric, so Filament never evaluates this
                                // closure — and thus never reaches this branch — otherwise.
                                if (! is_numeric($get('ticket_id'))) {
                                    return '—';
                                }

                                // @codeCoverageIgnoreEnd

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
                            ->helperText('Matched against known equipment automatically; an unmatched number is kept as free text.'),
                        Select::make('warranty_status')
                            ->label('Warranty')
                            ->options(collect(WarrantyStatus::cases())
                                ->mapWithKeys(static fn (WarrantyStatus $status): array => [$status->value => str($status->value)->headline()->toString()]))
                            ->default(WarrantyStatus::Unknown->value)
                            ->live()
                            ->required(),
                        DatePicker::make('warranty_expiry_date')
                            ->label('Warranty expiry date')
                            ->required(static fn (Get $get): bool => $get('warranty_status') === WarrantyStatus::Covered->value)
                            ->visible(static fn (Get $get): bool => $get('warranty_status') === WarrantyStatus::Covered->value),
                        Textarea::make('description')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
