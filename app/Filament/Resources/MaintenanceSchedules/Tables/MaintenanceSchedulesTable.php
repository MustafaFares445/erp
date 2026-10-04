<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Tables;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\MaintenanceSchedule;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class MaintenanceSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('schedule_number')->label(__('Schedule #'))->searchable(),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('serializedInventoryUnit.serial_number')->label(__('Equipment serial'))->searchable(),
                TextColumn::make('maintenance_kind')->label(__('Schedule type'))->badge(),
                TextColumn::make('name')->label(__('Maintenance')),
                TextColumn::make('recurrence')
                    ->label(__('Recurrence'))
                    ->getStateUsing(static fn (MaintenanceSchedule $record): string => sprintf(
                        'Every %d %s',
                        $record->interval_value,
                        $record->interval_type->label(),
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
            ->groups([
                Group::make('customer.company_name')->label(__('Customer')),
                Group::make('maintenance_kind')->label(__('Schedule type')),
                Group::make('billing_type')->label(__('Billing path')),
                Group::make('interval_type')->label(__('Recurrence')),
                Group::make('next_due_on')->label(__('Next due'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    TextConstraint::make('schedule_number')->label(__('Schedule #')),
                    TextConstraint::make('name')->label(__('Maintenance')),
                    RelationshipConstraint::make('customer')
                        ->label(__('Customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('serializedInventoryUnit')
                        ->label(__('Equipment serial'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('serial_number')->searchable()->multiple()),
                    SelectConstraint::make('maintenance_kind')
                        ->label(__('Schedule type'))
                        ->options(MaintenanceKind::class)
                        ->multiple(),
                    SelectConstraint::make('interval_type')
                        ->label(__('Recurrence'))
                        ->options(MaintenanceIntervalType::class)
                        ->multiple(),
                    SelectConstraint::make('billing_type')
                        ->label(__('Billing path'))
                        ->options(MaintenanceBillingType::class)
                        ->multiple(),
                    DateConstraint::make('next_due_on')->label(__('Next due')),
                    DateConstraint::make('last_completed_on')->label(__('Last completed')),
                ]),
                TernaryFilter::make('is_active')->label(__('Active')),
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
