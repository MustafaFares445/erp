<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('priority')
                    ->badge(),
                TextColumn::make('response_target_minutes')
                    ->label('Response target (minutes)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('resolution_target_minutes')
                    ->label('Resolution target (minutes)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updatedBy.name')
                    ->label('Last updated by')
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
