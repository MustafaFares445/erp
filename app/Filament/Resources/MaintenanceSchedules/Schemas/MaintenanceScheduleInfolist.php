<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MaintenanceScheduleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextEntry::make('schedule_number')->label('Schedule #'),
                    TextEntry::make('serializedInventoryUnit.serial_number')->label('Equipment'),
                    TextEntry::make('customer.company_name')->label('Customer'),
                    TextEntry::make('name'),
                    TextEntry::make('interval_type')->label('Recurrence unit')->badge(),
                    TextEntry::make('interval_value')->label('Recurrence value'),
                    TextEntry::make('lead_time_days')->label('Lead time (days)'),
                    TextEntry::make('first_due_on')->date(),
                    TextEntry::make('next_due_on')->date(),
                    TextEntry::make('last_completed_on')->date()->placeholder('—'),
                    TextEntry::make('is_active')->label('Active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    TextEntry::make('billing_type')->label('Intended billing path')->badge(),
                ]),
            ]);
    }
}
