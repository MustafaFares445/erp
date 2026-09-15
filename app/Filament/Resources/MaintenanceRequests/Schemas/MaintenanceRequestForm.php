<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use App\Enums\SerializedCustodyType;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
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
                    ->description('Ticket-backed requests inherit customer, equipment and warranty from ticket triage. Standalone requests can select known customer equipment or record an external/unlinked serial.')
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
                            ->live()
                            ->required(static fn (Get $get): bool => ! is_numeric($get('ticket_id')))
                            ->visible(static fn (Get $get): bool => ! is_numeric($get('ticket_id'))),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Known customer equipment')
                            ->options(static function (Get $get): array {
                                $customerId = $get('customer_id');

                                if (! is_numeric($customerId)) {
                                    return [];
                                }

                                return SerializedInventoryUnit::query()
                                    ->where('custody_type', SerializedCustodyType::Customer->value)
                                    ->where('custody_reference_id', (int) $customerId)
                                    ->with('productVariant')
                                    ->orderBy('serial_number')
                                    ->get()
                                    ->mapWithKeys(static function (SerializedInventoryUnit $unit): array {
                                        $variant = $unit->productVariant;
                                        $product = $variant instanceof ProductVariant ? $variant->name : 'Equipment';

                                        return [$unit->id => $product.' — '.$unit->serial_number];
                                    })
                                    ->all();
                            })
                            ->searchable()
                            ->live()
                            ->helperText('Optional. Select equipment already recorded in this customer custody.')
                            ->visible(static fn (Get $get): bool => ! is_numeric($get('ticket_id'))),
                        TextInput::make('serial_number')
                            ->label('External / unlinked serial number')
                            ->maxLength(255)
                            ->disabled(static fn (Get $get): bool => is_numeric($get('serialized_inventory_unit_id')))
                            ->helperText('Use this only when the equipment is not available in Known customer equipment.')
                            ->visible(static fn (Get $get): bool => ! is_numeric($get('ticket_id'))),
                        Placeholder::make('warranty_resolution')
                            ->label('Warranty')
                            ->content(static function (Get $get): string {
                                if (is_numeric($get('ticket_id'))) {
                                    return 'Inherited from the ticket triage decision.';
                                }

                                if (is_numeric($get('serialized_inventory_unit_id'))) {
                                    return 'Resolved automatically from the selected customer equipment.';
                                }

                                if (filled($get('serial_number'))) {
                                    return 'Known serials are validated against customer custody; unmatched serials are treated as external equipment.';
                                }

                                return 'Select known equipment or enter an external serial number.';
                            })
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
