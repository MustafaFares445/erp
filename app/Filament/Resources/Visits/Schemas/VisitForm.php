<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Enums\VisitStatus;
use App\Models\EmployeeProfile;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin field-edit escape hatch (FR-044) — reached only through
 * `employees.visit.field-edit`, so this form deliberately allows a direct
 * correction of any field rather than re-running the check-in/check-out
 * workflow the (out-of-scope) field app would normally drive.
 */
final class VisitForm
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
                            ->preload()
                            ->live()
                            ->required(),
                        Select::make('plan_task_id')
                            ->label('Plan task')
                            ->relationship(
                                name: 'planTask',
                                titleAttribute: 'title',
                                modifyQueryUsing: static fn (Builder $query, Get $get): Builder => $query->whereHas(
                                    'salesPlan',
                                    static fn (Builder $query): Builder => $query->where('employee_id', $get('employee_id')),
                                ),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->options(array_column(VisitStatus::cases(), 'value', 'value'))
                            ->required(),
                        DateTimePicker::make('planned_at'),
                        DateTimePicker::make('checked_in_at'),
                        DateTimePicker::make('checked_out_at'),
                        Textarea::make('outcome')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
