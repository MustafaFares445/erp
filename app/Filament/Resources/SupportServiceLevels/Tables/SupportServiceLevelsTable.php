<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels\Tables;

use App\Models\SupportServiceLevel;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportServiceLevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('description')->label(__('Description'))->limit(60)->toggleable(),
                TextColumn::make('entitlements_count')->counts('entitlements')->label(__('Entitlements'))->badge(),
                TextColumn::make('sla_policies_count')->counts('slaPolicies')->label(__('SLA policies'))->badge(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(static fn (SupportServiceLevel $record): bool => $record->entitlements()->exists()),
            ]);
    }
}
