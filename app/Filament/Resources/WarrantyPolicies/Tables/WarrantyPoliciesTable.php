<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Tables;

use App\Enums\WarrantyStartTrigger;
use App\Models\WarrantyPolicy;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class WarrantyPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')->badge()->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('period')
                    ->label(__('Coverage period'))
                    ->state(static fn (WarrantyPolicy $record): string => $record->duration_value.' '.$record->duration_unit->label()),
                TextColumn::make('start_trigger')
                    ->label(__('Begins from'))
                    ->formatStateUsing(static fn (WarrantyStartTrigger $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('coverage')
                    ->state(static fn (WarrantyPolicy $record): string => self::coverageSummary($record))
                    ->wrap(),
                IconColumn::make('transferable')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    private static function coverageSummary(WarrantyPolicy $record): string
    {
        return collect([
            __('Parts') => $record->covers_parts,
            __('Labour') => $record->covers_labour,
            __('Travel') => $record->covers_travel,
            __('Consumables') => $record->covers_consumables,
            __('Third-party services') => $record->covers_third_party,
        ])->filter()->keys()->implode(' · ') ?: __('No default categories');
    }
}
