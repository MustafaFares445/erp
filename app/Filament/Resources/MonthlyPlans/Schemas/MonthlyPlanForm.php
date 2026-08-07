<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class MonthlyPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->relationship('employee', 'job_title')
                            ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => sprintf('%s — %s', $record->employee_code, $record->job_title))
                            ->searchable()
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('name')->required()->maxLength(150),
                        DatePicker::make('month')
                            ->required()
                            ->displayFormat('F Y')
                            ->closeOnDateSelection()
                            ->disabledOn('edit'),
                        TextInput::make('required_visit_minutes')
                            ->numeric()
                            ->placeholder(static function (): string {
                                $default = config('employees.default_required_visit_minutes');

                                return is_scalar($default) ? (string) $default : '';
                            }),
                    ])
                    ->columns(2),
                Section::make('Weights')
                    ->description('The four weights must sum to exactly 100 before the plan can be activated.')
                    ->schema([
                        TextInput::make('task_weight')->numeric()->live()->required(),
                        TextInput::make('visit_weight')->numeric()->live()->required(),
                        TextInput::make('schedule_weight')->numeric()->live()->required(),
                        TextInput::make('work_time_weight')->numeric()->live()->required(),
                        Placeholder::make('weight_sum')
                            ->label('Current sum')
                            ->content(static function (Get $get): string {
                                $sum = self::toFloat($get('task_weight')) + self::toFloat($get('visit_weight'))
                                    + self::toFloat($get('schedule_weight')) + self::toFloat($get('work_time_weight'));

                                return number_format($sum, 2);
                            }),
                    ])
                    ->columns(5),
            ]);
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
