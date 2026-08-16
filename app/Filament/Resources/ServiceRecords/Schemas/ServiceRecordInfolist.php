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
                Section::make()
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('maintenanceRecord.id')->label('Maintenance request #'),
                        TextEntry::make('employee.user.name')->label('Assigned to')->placeholder('Unassigned'),
                        TextEntry::make('due_at')->dateTime()->placeholder('—'),
                        TextEntry::make('title')->columnSpanFull(),
                        TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
