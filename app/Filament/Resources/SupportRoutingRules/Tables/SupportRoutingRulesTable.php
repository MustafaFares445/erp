<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportRoutingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('precedence')
            ->columns([
                TextColumn::make('precedence')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('team.name')->label(__('Team')),
                TextColumn::make('ticket_type')->badge()->placeholder(__('Any type')),
                TextColumn::make('service_path')->badge()->placeholder(__('Any path')),
                TextColumn::make('requiredSkill.name')->label(__('Required skill'))->placeholder(__('Any skill')),
                IconColumn::make('auto_assign')->label(__('Auto-assign'))->boolean(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
