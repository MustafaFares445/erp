<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('precedence')
            ->columns([
                TextColumn::make('precedence')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable()->toggleable(),
                TextColumn::make('priority')->badge()->placeholder(__('Any priority')),
                TextColumn::make('ticket_type')->badge()->placeholder(__('Any type'))->toggleable(),
                TextColumn::make('service_path')->badge()->placeholder(__('Any path'))->toggleable(),
                TextColumn::make('supportTeam.name')->label(__('Team'))->placeholder(__('Any team'))->toggleable(),
                TextColumn::make('serviceLevel.name')->label(__('Service level'))->placeholder(__('Any level'))->toggleable(),
                TextColumn::make('calendar.name')->label(__('Calendar')),
                TextColumn::make('milestones_count')->counts('milestones')->label(__('Milestones'))->badge(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('updatedBy.name')->label(__('Last updated by'))->placeholder(__('—'))->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
