<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\Tables;

use App\Enums\SalaryCalculationStatus;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Services\Employees\SalaryRecalculationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SalaryCalculationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('salesPlan.name')->label('Plan')->searchable(),
                TextColumn::make('payable_base')->money('AED'),
                TextColumn::make('performance_percent')->suffix('%'),
                TextColumn::make('bonus_amount')->money('AED'),
                TextColumn::make('final_salary')->money('AED')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(SalaryCalculationStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize('confirm')
                    ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->status === SalaryCalculationStatus::PendingConfirmation)
                    ->action(static function (EmployeeSalaryCalculation $record): void {
                        app(SalaryRecalculationService::class)->confirm($record);
                    }),
                Action::make('recalculate')
                    ->label('Recalculate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->authorize('create')
                    ->action(static function (EmployeeSalaryCalculation $record): void {
                        $plan = $record->salesPlan;

                        if ($plan instanceof SalesPlan) {
                            app(SalaryRecalculationService::class)->recalculate($plan);
                        }
                    }),
            ]);
    }
}
