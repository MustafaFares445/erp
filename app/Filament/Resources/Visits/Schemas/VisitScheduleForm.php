<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class VisitScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Visit assignment'))
                ->description(__('Assign the customer visit to an employee and one of that employee’s plan tasks.'))
                ->columns(2)
                ->schema([
                    Select::make('employee_id')
                        ->label(__('Employee'))
                        ->relationship('employee', 'employee_code')
                        ->getOptionLabelFromRecordUsing(static fn (EmployeeProfile $record): string => sprintf('%s — %s', $record->employee_code, $record->job_title))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('plan_task_id')
                        ->label(__('Plan task'))
                        ->relationship('planTask', 'title')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('customer_id')
                        ->label(__('Customer'))
                        ->relationship('customer', 'company_name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('visit_type')
                        ->label(__('Visit type'))
                        ->maxLength(80)
                        ->placeholder(__('Customer meeting, demo, follow-up…')),
                ]),
            Section::make(__('Schedule'))
                ->description(__('Overlapping visits for the same employee are blocked unless an explicit override reason is recorded.'))
                ->columns(2)
                ->schema([
                    DateTimePicker::make('scheduled_start_at')->label(__('Scheduled start'))->required()->seconds(false),
                    DateTimePicker::make('scheduled_end_at')->label(__('Scheduled end'))->required()->seconds(false),
                    Toggle::make('override_conflict')
                        ->label(__('Override scheduling conflict'))
                        ->helperText(__('Use only when the employee must intentionally have overlapping visits.'))
                        ->live(),
                    Textarea::make('override_reason')
                        ->label(__('Conflict override reason'))
                        ->required(static fn (Get $get): bool => (bool) $get('override_conflict'))
                        ->visible(static fn (Get $get): bool => (bool) $get('override_conflict'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
