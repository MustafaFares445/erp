<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Schemas;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MaintenanceScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Preventive Maintenance Schedule')
                    ->schema([
                        Select::make('serialized_inventory_unit_id')
                            ->label('Equipment')
                            ->relationship('serializedInventoryUnit', 'serial_number')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Schedule name')
                            ->required()
                            ->maxLength(255),
                        Select::make('interval_type')
                            ->label('Recurrence unit')
                            ->options(collect(MaintenanceIntervalType::cases())
                                ->mapWithKeys(static fn (MaintenanceIntervalType $type): array => [$type->value => str($type->value)->headline()->toString()]))
                            ->required(),
                        TextInput::make('interval_value')
                            ->label('Recurrence value')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('lead_time_days')
                            ->label('Lead time (days)')
                            ->helperText('How many days in advance a due request is raised (MT-07).')
                            ->numeric()
                            ->minValue(0)
                            ->default(7)
                            ->required(),
                        DatePicker::make('first_due_on')
                            ->label('First due date')
                            ->required(),
                        Select::make('billing_type')
                            ->label('Intended billing path')
                            ->options(collect(MaintenanceBillingType::cases())
                                ->mapWithKeys(static fn (MaintenanceBillingType $type): array => [$type->value => str($type->value)->headline()->toString()]))
                            ->default(MaintenanceBillingType::Unbilled->value)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
