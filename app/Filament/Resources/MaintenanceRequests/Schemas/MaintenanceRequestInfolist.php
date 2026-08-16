<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MaintenanceRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('customer.company_name')->label('Customer'),
                        TextEntry::make('ticket.ticket_number')->label('Raised from ticket')->placeholder('Standalone'),
                        TextEntry::make('serial_number')->label('Serial number')->placeholder('—'),
                        TextEntry::make('serializedInventoryUnit.productVariant.name')->label('Equipment')->placeholder('Unlinked'),
                        TextEntry::make('is_equipment_unlinked')
                            ->label('Equipment status')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Unlinked equipment' : 'Linked or no serial entered')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                        TextEntry::make('warranty_status')->label('Warranty')->badge(),
                        TextEntry::make('warranty_expiry_date')->label('Warranty expiry')->date()->placeholder('—'),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
