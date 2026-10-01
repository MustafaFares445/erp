<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\RelationManagers;

use App\Enums\OccurrenceStatus;
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
                TextColumn::make('status')->badge()->color(fn (OccurrenceStatus $state): string => match ($state) {
                    OccurrenceStatus::Missed => 'danger',
                    OccurrenceStatus::Completed => 'success',
                    OccurrenceStatus::Raised => 'warning',
                    OccurrenceStatus::Skipped => 'gray',
                    default => 'info',
                }),
                TextColumn::make('maintenanceRecord.id')->label(__('Job #'))->placeholder(__('—')),
                TextColumn::make('raised_at')->dateTime()->placeholder(__('—')),
                TextColumn::make('completed_at')->dateTime()->placeholder(__('—')),
                TextColumn::make('skipped_reason')->label(__('Skip reason'))->placeholder(__('—'))->limit(40),
            ])
            ->recordActions([
                MaintenanceScheduleActions::skipOccurrence(),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
