<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Tables;

use App\Filament\Tables\TableFit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('precedence')
            ->columns([
                TextColumn::make('precedence')->sortable(),
                TextColumn::make('name')->wrap()->searchable(),
                TextColumn::make('code')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')->badge()->placeholder(__('Any priority')),
                TextColumn::make('ticket_type')->badge()->placeholder(__('Any type'))->toggleable(),
                TextColumn::make('service_path')->badge()->placeholder(__('Any path'))->toggleable(),
                TextColumn::make('supportTeam.name')->wrap()->label(__('Team'))->placeholder(__('Any team'))->toggleable(),
                TextColumn::make('serviceLevel.name')->wrap()->label(__('Service level'))->placeholder(__('Any level'))->toggleable(),
                TextColumn::make('calendar.name')->wrap()->label(__('Calendar')),
                TextColumn::make('milestones_count')->counts('milestones')->label(__('Milestones'))->badge(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('updatedBy.name')->wrap()->label(__('Last updated by'))->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions(TableFit::rowActions([EditAction::make(), DeleteAction::make()]));
    }
}
