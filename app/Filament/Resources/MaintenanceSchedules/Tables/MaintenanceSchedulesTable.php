<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Tables;

use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use App\Models\MaintenanceSchedule;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class MaintenanceSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('schedule_number')->label(__('Schedule #'))->searchable(),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('serializedInventoryUnit.serial_number')->label(__('Equipment serial'))->searchable(),
                TextColumn::make('name')->label(__('Maintenance')),
                TextColumn::make('recurrence')
                    ->label(__('Recurrence'))
                    ->getStateUsing(static fn (MaintenanceSchedule $record): string => sprintf(
                        'Every %d %s',
                        $record->interval_value,
                        __(str($record->interval_type->value)->headline()->toString()),
                    )),
                TextColumn::make('next_due_on')->label(__('Next due'))->date()->sortable(),
                TextColumn::make('due_state')
                    ->label(__('Due state'))
                    ->badge()
                    ->getStateUsing(static fn (MaintenanceSchedule $record): string => self::dueState($record))
                    ->color(static fn (MaintenanceSchedule $record): string => match (self::dueState($record)) {
                        'Overdue' => 'danger',
                        'Due Soon' => 'warning',
                        'Upcoming' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('last_completed_on')->date()->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('billing_type')->label(__('Billing path'))->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active')),
                SelectFilter::make('serialized_inventory_unit_id')
                    ->label(__('Equipment serial'))
                    ->relationship('serializedInventoryUnit', 'serial_number')
                    ->searchable()
                    ->preload(),
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

    private static function dueState(MaintenanceSchedule $schedule): string
    {
        if (! $schedule->is_active) {
            return 'Inactive';
        }

        $nextDue = $schedule->next_due_on;

        if ($nextDue->startOfDay()->lt(now()->startOfDay())) {
            return 'Overdue';
        }

        if ($nextDue->startOfDay()->lte(now()->addDays($schedule->lead_time_days)->startOfDay())) {
            return 'Due Soon';
        }

        return 'Upcoming';
    }
}
