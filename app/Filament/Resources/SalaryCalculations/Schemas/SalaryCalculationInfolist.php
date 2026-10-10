<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryCalculations\Schemas;

use App\Enums\SalaryCalculationMode;
use App\Enums\SalaryCalculationStatus;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeSalaryCalculation;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SalaryCalculationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('pending_confirmation_banner')
                ->label('')
                ->hiddenLabel()
                ->state(__('This calculation is awaiting manual review and confirmation. No confirmed salary history is replaced until the calculation is confirmed.'))
                ->color('warning')
                ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->status === SalaryCalculationStatus::PendingConfirmation),
            Section::make(__('Calculation snapshot'))
                ->description(__('These values are stored with this calculation so later employee/profile changes cannot rewrite salary history.'))
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.user.name')->label(__('Employee')),
                    TextEntry::make('salesPlan.name')->label(__('Plan')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(static fn (SalaryCalculationStatus $state): string => $state->label()),
                    IconEntry::make('use_base_salary_snapshot')->label(__('Used base salary'))->boolean(),
                    TextEntry::make('base_salary_snapshot')->label(__('Base salary snapshot'))->money()->placeholder(__('Not used')),
                    TextEntry::make('salary_calculation_mode_snapshot')
                        ->label(__('Calculation mode'))
                        ->formatStateUsing(static fn (?string $state): string => SalaryCalculationMode::tryFrom($state ?? '')?->label() ?? $state ?? __('—'))
                        ->placeholder(__('—')),
                    TextEntry::make('payable_base')->label(__('Payable base'))->money(),
                    TextEntry::make('performance_percent')->label(__('Performance'))->suffix('%'),
                    TextEntry::make('bonus_amount')->label(__('Approved bonus'))->money(),
                    TextEntry::make('final_salary')->label(__('Final salary'))->money(),
                    TextEntry::make('performance_snapshot')
                        ->label(__('Performance snapshot'))
                        ->state(static fn (EmployeeSalaryCalculation $record): string => $record->performanceScore instanceof EmployeePerformanceScore
                            ? __('Total score :score%', ['score' => self::percent($record->performanceScore->total_score)])
                            : __('Legacy / unavailable'))
                        ->url(static fn (EmployeeSalaryCalculation $record): ?string => $record->performanceScore instanceof EmployeePerformanceScore && PerformanceResource::canView($record->performanceScore)
                            ? PerformanceResource::getUrl('view', ['record' => $record->performanceScore])
                            : null),
                    TextEntry::make('performanceScore.period_start')->label(__('Performance period start'))->date()->placeholder(__('—')),
                    TextEntry::make('performanceScore.period_end')->label(__('Performance period end'))->date()->placeholder(__('—')),
                    TextEntry::make('formula')
                        ->label(__('Formula'))
                        ->state(static fn (EmployeeSalaryCalculation $record): string => self::formula($record))
                        ->columnSpanFull(),
                ]),
            Section::make(__('Manual review'))
                ->description(__('Review the performance snapshot, payable base, approved bonus and final amount before confirmation.'))
                ->columns(2)
                ->schema([
                    TextEntry::make('review_performance')
                        ->label(__('Performance contribution'))
                        ->state(static fn (EmployeeSalaryCalculation $record): string => self::percent(self::explanation($record, 'performance_percent', (string) $record->performance_percent)).'%'),
                    TextEntry::make('review_bonus')
                        ->label(__('Approved bonus used'))
                        ->state(static fn (EmployeeSalaryCalculation $record): string => self::explanation($record, 'approved_bonus_amount', (string) $record->bonus_amount))
                        ->money(),
                    TextEntry::make('confirmedBy.name')->label(__('Confirmed by'))->placeholder(__('Not yet confirmed')),
                    TextEntry::make('confirmed_at')->dateTime()->placeholder(__('—')),
                ]),
            Section::make(__('History'))
                ->key('history')
                ->columns(2)
                ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->superseded_at !== null || $record->superseded_by_id !== null)
                ->schema([
                    TextEntry::make('superseded_at')->dateTime()->placeholder(__('—'))
                        ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->superseded_at !== null),
                    TextEntry::make('superseded_by_id')
                        ->label(__('Superseded by'))
                        ->formatStateUsing(static fn (): string => __('View replacement calculation'))
                        ->url(static fn (EmployeeSalaryCalculation $record): ?string => $record->superseded_by_id !== null
                            ? SalaryCalculationResource::getUrl('view', ['record' => $record->superseded_by_id])
                            : null)
                        ->visible(static fn (EmployeeSalaryCalculation $record): bool => $record->superseded_by_id !== null),
                ]),
        ]);
    }

    private static function explanation(EmployeeSalaryCalculation $record, string $key, string $fallback): string
    {
        $value = $record->calculation_explanation[$key] ?? null;

        return is_scalar($value) ? (string) $value : $fallback;
    }

    private static function percent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2) : (is_scalar($value) ? (string) $value : '');
    }

    private static function formula(EmployeeSalaryCalculation $record): string
    {
        $formula = $record->calculation_explanation['formula'] ?? null;

        if ($formula === 'payable_base × performance_percent / 100 + approved_bonus_amount') {
            return __('Payable base').' × '.__('Performance').' / 100 + '.__('Approved bonus');
        }

        return is_scalar($formula) ? (string) $formula : __('Stored calculation formula unavailable');
    }
}
