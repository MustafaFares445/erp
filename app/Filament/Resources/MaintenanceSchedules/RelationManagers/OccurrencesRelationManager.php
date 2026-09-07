<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\RelationManagers;

use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('due_on')
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('due_on')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'missed' => 'danger',
                    'completed' => 'success',
                    'raised' => 'warning',
                    'skipped' => 'gray',
                    default => 'info',
                }),
                TextColumn::make('maintenanceRecord.id')->label('Job #')->placeholder('—'),
                TextColumn::make('raised_at')->dateTime()->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('skipped_reason')->label('Skip reason')->placeholder('—')->limit(40),
            ])
            ->recordActions([
                MaintenanceScheduleActions::skipOccurrence(),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
