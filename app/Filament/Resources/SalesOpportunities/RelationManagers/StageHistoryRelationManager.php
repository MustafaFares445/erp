<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StageHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'stageTransitions';
    protected static ?string $title = 'Stage history';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(), TextColumn::make('from_stage')->badge(), TextColumn::make('to_stage')->badge(),
            TextColumn::make('interaction.summary')->label('Interaction')->placeholder('—')->wrap(), TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
        ]);
    }
}
