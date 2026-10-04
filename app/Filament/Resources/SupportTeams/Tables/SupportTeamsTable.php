<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportTeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('assignment_strategy')->label(__('Assignment'))->badge(),
            TextColumn::make('members_count')->counts('members')->label(__('Members')),
            TextColumn::make('default_capacity')->label(__('Default capacity'))->placeholder(__('Unlimited')),
            TextColumn::make('manager.name')->label(__('Manager'))->placeholder(__('—')),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }
}
