<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules\Tables;

use App\Enums\SupportAutomationEvent;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportAutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('precedence')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('event_key')
                    ->label(__('Event'))
                    ->badge()
                    ->formatStateUsing(static fn (SupportAutomationEvent $state): string => $state->label()),
                TextColumn::make('precedence')->sortable(),
                TextColumn::make('runs_count')->counts('runs')->label(__('Runs')),
                IconColumn::make('stop_processing')->boolean()->label(__('Stops chain')),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
