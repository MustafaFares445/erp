<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performance\Schemas;

use App\Models\EmployeePerformanceScore;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * FR-061 preview — every value here is read directly from
 * `calculation_breakdown`, never recomputed, so the screen can never drift
 * from the row that actually determined pay.
 */
final class PerformanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.user.name')->label(__('Employee')),
                    TextEntry::make('salesPlan.name')->label(__('Plan')),
                    TextEntry::make('total_score')->label(__('Total score'))->suffix('%'),
                    TextEntry::make('task_completion_percent')->label(__('Task completion (statistic)'))->suffix('%'),
                    TextEntry::make('period_start')->label(__('Period start'))->date(),
                    TextEntry::make('period_end')->label(__('Period end'))->date(),
                    TextEntry::make('calculated_at')->dateTime(),
                ]),
            Section::make(__('Factor breakdown'))
                ->schema([
                    RepeatableEntry::make('factors')
                        ->label('')
                        ->state(static fn (EmployeePerformanceScore $record): array => self::factorRows($record))
                        ->schema([
                            TextEntry::make('label')->label(__('Factor')),
                            TextEntry::make('numerator')->label(__('Completed/on-time')),
                            TextEntry::make('denominator')->label(__('Total'))->formatStateUsing(
                                static fn (int $state): string => $state === 0 ? 'No data' : (string) $state,
                            ),
                            TextEntry::make('ratio')->label(__('Ratio')),
                            TextEntry::make('weight')->label(__('Weight')),
                            TextEntry::make('contribution')->label(__('Contribution')),
                        ])
                        ->columns(6),
                ]),
        ]);
    }

    /**
     * @return list<array{label: string, numerator: int, denominator: int, ratio: float, weight: float, contribution: float}>
     */
    private static function factorRows(EmployeePerformanceScore $record): array
    {
        $breakdown = $record->calculation_breakdown;
        $labels = [
            'task_completion' => 'Task completion',
            'visit_completion' => 'Visit completion',
            'schedule_adherence' => 'Schedule adherence',
            'work_time_adherence' => 'Work-time adherence',
            'potential_sales_opportunities' => 'Potential sales opportunities',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            $factor = $breakdown[$key] ?? null;

            if (! is_array($factor)) {
                continue;
            }

            $numerator = $factor['numerator'] ?? 0;
            $denominator = $factor['denominator'] ?? 0;
            $ratio = $factor['ratio'] ?? 0.0;
            $weight = $factor['weight'] ?? 0.0;
            $contribution = $factor['contribution'] ?? 0.0;

            $rows[] = [
                'label' => $label,
                'numerator' => is_numeric($numerator) ? (int) $numerator : 0,
                'denominator' => is_numeric($denominator) ? (int) $denominator : 0,
                'ratio' => is_numeric($ratio) ? (float) $ratio : 0.0,
                'weight' => is_numeric($weight) ? (float) $weight : 0.0,
                'contribution' => is_numeric($contribution) ? (float) $contribution : 0.0,
            ];
        }

        return $rows;
    }
}
