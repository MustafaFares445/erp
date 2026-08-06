<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MonthlyPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('employee.job_title')->label('Employee'),
                        TextEntry::make('name'),
                        TextEntry::make('month')->date('F Y'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('required_visit_minutes')
                            ->label('Required visit minutes')
                            ->formatStateUsing(static function (?int $state): string {
                                if ($state !== null) {
                                    return (string) $state;
                                }

                                $default = config('employees.default_required_visit_minutes');

                                return is_scalar($default) ? (string) $default : '';
                            }),
                    ])
                    ->columns(2),
                Section::make('Weights')
                    ->schema([
                        TextEntry::make('task_weight'),
                        TextEntry::make('visit_weight'),
                        TextEntry::make('schedule_weight'),
                        TextEntry::make('work_time_weight'),
                    ])
                    ->columns(4),
            ]);
    }
}
