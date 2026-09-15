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
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('serializedInventoryUnit.serial_number')->label('Equipment serial')->searchable(),
                TextColumn::make('name')->label('Maintenance'),
                TextColumn::make('recurrence')
                    ->label('Recurrence')
                    ->getStateUsing(static fn (MaintenanceSchedule $record): string => sprintf(
                        'Every %d %s',
                        $record->interval_value,
                        str($record->interval_type->value)->headline()->toString(),
                    )),
                TextColumn::make('next_due_on')->label('Next due')->date()->sortable(),
                TextColumn::make('due_state')
                    ->label('Due state')
                    ->badge()
                    ->getStateUsing(static fn (MaintenanceSchedule $record): string => self::dueState($record))
                    ->color(static fn (MaintenanceSchedule $record): string => match (self::dueState($record)) {
                        'Overdue' => 'danger',
                        'Due Soon' => 'warning',
                        'Upcoming' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_completed_on')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
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

    private static function dueState(MaintenanceSchedule $schedule): string
    {
        if (! $schedule->is_active) {
            return 'Inactive';
        }

        $nextDue = $schedule->next_due_on;

        if ($nextDue === null) {
            return 'Upcoming';
        }

        if ($nextDue->startOfDay()->lt(now()->startOfDay())) {
            return 'Overdue';
        }

        if ($nextDue->startOfDay()->lte(now()->addDays($schedule->lead_time_days)->startOfDay())) {
            return 'Due Soon';
        }

        return 'Upcoming';
    }
}
