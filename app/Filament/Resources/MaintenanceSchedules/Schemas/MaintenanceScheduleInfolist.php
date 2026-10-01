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
                    TextEntry::make('schedule_number')->label(__('Schedule #')),
                    TextEntry::make('serializedInventoryUnit.serial_number')->label(__('Equipment')),
                    TextEntry::make('customer.company_name')->label(__('Customer')),
                    TextEntry::make('name'),
                    TextEntry::make('interval_type')->label(__('Recurrence unit'))->badge(),
                    TextEntry::make('interval_value')->label(__('Recurrence value')),
                    TextEntry::make('lead_time_days')->label(__('Lead time (days)')),
                    TextEntry::make('first_due_on')->date(),
                    TextEntry::make('next_due_on')->date(),
                    TextEntry::make('last_completed_on')->date()->placeholder(__('—')),
                    TextEntry::make('is_active')->label(__('Active'))->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    TextEntry::make('billing_type')->label(__('Intended billing path'))->badge(),
                ]),
            ]);
    }
}
