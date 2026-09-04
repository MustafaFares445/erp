<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class LeadInteractionsRelationManager extends RelationManager
{
    protected static string $relationship = 'interactions';
    protected static ?string $title = 'Interactions';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('direction')->badge(),
            TextColumn::make('summary')->wrap(),
            TextColumn::make('employee.name')->label('Employee'),
            TextColumn::make('outcome')->badge()->placeholder('—'),
        ]);
    }
}
