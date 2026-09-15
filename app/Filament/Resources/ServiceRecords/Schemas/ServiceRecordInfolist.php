<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ServiceRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Service Record')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('maintenanceRecord.id')->label('Maintenance request #'),
                        TextEntry::make('maintenanceRecord.customer.company_name')->label('Customer'),
                        TextEntry::make('maintenanceRecord.serializedInventoryUnit.productVariant.name')->label('Equipment')->placeholder('External / unlinked'),
                        TextEntry::make('maintenanceRecord.serial_number')->label('Serial')->placeholder('—'),
                        TextEntry::make('employee.user.name')->label('Technician')->placeholder('Unassigned'),
                        TextEntry::make('due_at')->label('Due')->dateTime()->placeholder('—'),
                        TextEntry::make('started_at')->label('Started')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
                        TextEntry::make('title')->label('Work')->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Execution')
                    ->schema([
                        TextEntry::make('work_performed')
                            ->label('Work performed')
                            ->placeholder('Not completed yet')
                            ->columnSpanFull(),
                        TextEntry::make('completion_notes')
                            ->label('Completion notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
