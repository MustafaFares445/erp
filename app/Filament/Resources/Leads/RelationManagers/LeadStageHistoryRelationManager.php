<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LeadStageHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'stageTransitions';

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Stage history');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('from_status')->badge()->placeholder(__('Created')),
            TextColumn::make('to_status')->badge(),
            TextColumn::make('reason')->wrap()->placeholder(__('—')),
            TextColumn::make('actor.name')->label(__('Actor'))->placeholder(__('System')),
        ]);
    }
}
