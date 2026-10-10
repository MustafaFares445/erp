<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance\Tables;

use App\Filament\Tables\TableFit;
use App\Models\EmployeePerformanceScore;
use App\Models\SalesPlan;
use App\Services\Employees\PerformanceScoringService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PerformanceTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('calculated_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->wrap()->label(__('Employee'))->searchable()->sortable(),
                TextColumn::make('salesPlan.name')->wrap()->label(__('Plan'))->searchable(),
                TextColumn::make('salesPlan.month')->label(__('Plan month'))->date('Y-m')->sortable(),
                TextColumn::make('period_start')->label(__('Score period start'))->date('Y-m')->sortable(),
                TextColumn::make('total_score')->label(__('Total score'))->suffix('%')->sortable(),
                TextColumn::make('task_completion_percent')->label(__('Task completion'))->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opportunity_score')->label(__('Opportunity contribution'))->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('calculated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')->relationship('employee', 'employee_code')->searchable()->preload(),
                Filter::make('period')
                    ->schema([DatePicker::make('month')->label(__('Month'))])
                    ->query(static function (Builder $query, array $data): Builder {
                        $month = $data['month'] ?? null;

                        if (! is_string($month) || $month === '') {
                            return $query;
                        }

                        $timestamp = strtotime($month);

                        if ($timestamp === false) {
                            return $query;
                        }

                        return $query
                            ->whereYear('period_start', date('Y', $timestamp))
                            ->whereMonth('period_start', date('m', $timestamp));
                    }),
            ])
            ->recordActions(TableFit::rowActions([
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('recalculate')
                        ->label(__('Recalculate'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->requiresConfirmation()
                        ->authorize('recalculate')
                        ->action(static function (EmployeePerformanceScore $record): void {
                            $plan = $record->salesPlan;

                            if ($plan instanceof SalesPlan) {
                                app(PerformanceScoringService::class)->scoreForPlan($plan);
                            }
                        }),
                ]),
            ]));
    }
}
