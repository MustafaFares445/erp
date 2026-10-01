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
                Section::make(__('Service Record'))
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('maintenanceRecord.id')->label(__('Maintenance request #')),
                        TextEntry::make('maintenanceRecord.customer.company_name')->label(__('Customer')),
                        TextEntry::make('maintenanceRecord.serializedInventoryUnit.productVariant.name')->label(__('Equipment'))->placeholder(__('External / unlinked')),
                        TextEntry::make('maintenanceRecord.serial_number')->label(__('Serial'))->placeholder(__('—')),
                        TextEntry::make('employee.user.name')->label(__('Technician'))->placeholder(__('Unassigned')),
                        TextEntry::make('due_at')->label(__('Due'))->dateTime()->placeholder(__('—')),
                        TextEntry::make('started_at')->label(__('Started'))->dateTime()->placeholder(__('—')),
                        TextEntry::make('completed_at')->label(__('Completed'))->dateTime()->placeholder(__('—')),
                        TextEntry::make('title')->label(__('Work'))->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull()->placeholder(__('—')),
                    ])
                    ->columns(2),
                Section::make(__('Execution'))
                    ->schema([
                        TextEntry::make('work_performed')
                            ->label(__('Work performed'))
                            ->placeholder(__('Not completed yet'))
                            ->columnSpanFull(),
                        TextEntry::make('completion_notes')
                            ->label(__('Completion notes'))
                            ->placeholder(__('—'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
