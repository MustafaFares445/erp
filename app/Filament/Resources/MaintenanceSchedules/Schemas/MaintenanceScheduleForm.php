<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Schemas;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\SerializedCustodyType;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class MaintenanceScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Preventive Maintenance Schedule')
                    ->description('Customer, equipment and the first due date define the schedule identity and are locked after creation.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->disabledOn('edit')
                            ->required(),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Equipment')
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
                            ->disabledOn('edit')
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
                            ->helperText('How many days in advance a due request is raised.')
                            ->numeric()
                            ->minValue(0)
                            ->default(7)
                            ->required(),
                        DatePicker::make('first_due_on')
                            ->label('First due date')
                            ->disabledOn('edit')
                            ->required(),
                        Select::make('billing_type')
                            ->label('Default Service Billing')
                            ->helperText('The generated maintenance request remains unbilled until completion. This value records the intended settlement path.')
                            ->options(collect(MaintenanceBillingType::cases())
                                ->mapWithKeys(static fn (MaintenanceBillingType $type): array => [$type->value => str($type->value)->headline()->toString()]))
                            ->default(MaintenanceBillingType::Unbilled->value)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
