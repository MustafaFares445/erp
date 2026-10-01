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
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('period')
                    ->label(__('Coverage period'))
                    ->state(static fn (WarrantyPolicy $record): string => $record->duration_value.' '.$record->duration_unit->value),
                TextColumn::make('start_trigger')
                    ->label(__('Begins from'))
                    ->formatStateUsing(static fn (WarrantyStartTrigger $state): string => $state->label()),
                TextColumn::make('coverage')
                    ->state(static fn (WarrantyPolicy $record): string => collect([
                        'Parts' => $record->covers_parts,
                        'Labour' => $record->covers_labour,
                        'Travel' => $record->covers_travel,
                        'Consumables' => $record->covers_consumables,
                        'Third party' => $record->covers_third_party,
                    ])->filter()->keys()->implode(', ') ?: 'No default categories')
                    ->wrap(),
                IconColumn::make('transferable')->boolean(),
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
}
