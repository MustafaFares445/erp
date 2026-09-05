<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class LeadStageHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'stageTransitions';

    protected static ?string $title = 'Stage history';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('from_status')->badge()->placeholder('Created'),
            TextColumn::make('to_status')->badge(),
            TextColumn::make('reason')->wrap()->placeholder('—'),
            TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
        ]);
    }
}
