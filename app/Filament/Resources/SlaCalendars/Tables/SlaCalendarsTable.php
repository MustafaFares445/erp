<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaCalendars\Tables;

use App\Models\SlaCalendar;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SlaCalendarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('timezone')->label(__('Timezone'))->toggleable(),
                IconColumn::make('is_24x7')->label(__('Runs 24x7'))->boolean(),
                TextColumn::make('periods_count')->counts('periods')->label(__('Weekly periods'))->badge(),
                TextColumn::make('exceptions_count')->counts('exceptions')->label(__('Exceptions'))->badge(),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(static fn (SlaCalendar $record): bool => $record->is_default),
            ]);
    }
}
