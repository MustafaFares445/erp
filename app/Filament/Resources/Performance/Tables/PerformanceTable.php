<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance\Tables;

use App\Models\EmployeePerformanceScore;
use App\Models\SalesPlan;
use App\Services\Employees\PerformanceScoringService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PerformanceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('calculated_at', 'desc')
            ->columns([
                TextColumn::make('employee.user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('salesPlan.name')->label('Plan')->searchable(),
                TextColumn::make('salesPlan.month')->label('Month')->date('Y-m')->sortable(),
                TextColumn::make('total_score')->label('Total score')->suffix('%')->sortable(),
                TextColumn::make('task_completion_percent')->label('Task completion')->suffix('%'),
                TextColumn::make('calculated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('recalculate')
                    ->label('Recalculate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->authorize('recalculate')
                    ->action(static function (EmployeePerformanceScore $record): void {
                        $plan = $record->salesPlan;

                        if ($plan instanceof SalesPlan) {
                            app(PerformanceScoringService::class)->scoreForPlan($plan);
                        }
                    }),
            ]);
    }
}
