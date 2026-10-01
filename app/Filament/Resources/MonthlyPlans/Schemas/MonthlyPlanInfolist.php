<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Schemas;

use App\Enums\SalesPlanStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MonthlyPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SalesPlanStageBar::make(),
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make(__('General'))
                                ->schema([
                                    TextEntry::make('name'),
                                    TextEntry::make('employee.job_title')->label(__('Employee')),
                                    TextEntry::make('month')->date('F Y'),
                                ])
                                ->columns(3),
                            Section::make(__('Additional Information'))
                                ->schema([
                                    TextEntry::make('status')
                                        ->label(__('Current Stage'))
                                        ->badge()
                                        ->color(self::statusColor(...)),
                                    TextEntry::make('tasks_count')->label(__('Tasks')),
                                    TextEntry::make('required_visit_minutes')
                                        ->label(__('Required visit minutes'))
                                        ->formatStateUsing(static function (?int $state): string {
                                            if ($state !== null) {
                                                return (string) $state;
                                            }

                                            $default = config('employees.default_required_visit_minutes');

                                            return is_scalar($default) ? (string) $default : '';
                                        }),
                                    TextEntry::make('active_month')
                                        ->label(__('Active month'))
                                        ->date('F Y')
                                        ->placeholder(__('—')),
                                ])
                                ->columns(2),
                            Section::make(__('Performance'))
                                ->schema([
                                    PerformanceProgressBar::make(),
                                ]),
                        ])->columnSpan(['lg' => 2]),
                        Group::make([
                            Section::make(__('Record Information'))
                                ->schema([
                                    TextEntry::make('created_at')->dateTime(),
                                    TextEntry::make('createdBy.name')->label(__('Created By'))->placeholder(__('—')),
                                    TextEntry::make('updated_at')->label(__('Last Updated'))->dateTime(),
                                ]),
                            Section::make(__('Scoring Weights'))
                                ->schema([
                                    TextEntry::make('task_weight'),
                                    TextEntry::make('visit_weight'),
                                    TextEntry::make('schedule_weight'),
                                    TextEntry::make('work_time_weight'),
                                ])
                                ->columns(2),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    private static function statusColor(SalesPlanStatus $state): string
    {
        return match ($state) {
            SalesPlanStatus::Draft => 'gray',
            SalesPlanStatus::Active => 'primary',
            SalesPlanStatus::Paused => 'warning',
            SalesPlanStatus::Completed => 'success',
            SalesPlanStatus::Archived => 'danger',
        };
    }
}
