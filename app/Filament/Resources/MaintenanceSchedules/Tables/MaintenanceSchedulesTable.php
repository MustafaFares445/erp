<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Tables;

use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class MaintenanceSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('schedule_number')->label('Schedule #')->searchable(),
                TextColumn::make('serializedInventoryUnit.serial_number')->label('Equipment')->searchable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('name'),
                TextColumn::make('interval_type')->label('Recurrence')->badge(),
                TextColumn::make('next_due_on')->date()->sortable(),
                TextColumn::make('last_completed_on')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('billing_type')->label('Billing path')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    MaintenanceScheduleActions::raiseNow(),
                    MaintenanceScheduleActions::deactivate(),
                ]),
            ]);
    }
}
