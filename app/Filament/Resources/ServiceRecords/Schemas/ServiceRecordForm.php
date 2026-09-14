<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ServiceRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Service Record')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        Select::make('employee_id')
                            ->label('Assignee')
                            ->options(fn (): array => EmployeeProfile::query()->with('user')->get()
                                ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                                ->all())
                            ->searchable(),
                        DateTimePicker::make('due_at'),
                        Textarea::make('description')->rows(4)->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Execution')
                    ->description('Actual start/completion timestamps are controlled by the service-record lifecycle.')
                    ->schema([
                        Textarea::make('work_performed')
                            ->label('Work performed')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('completion_notes')
                            ->label('Completion notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
