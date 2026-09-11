<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReplenishmentRequirements\Tables;

use App\Enums\ReplenishmentRequirementStatus;
use App\Models\ReplenishmentRequirement;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ReplenishmentRequirementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('triggered_at', 'desc')
            ->columns([
                TextColumn::make('warehouse.code')
                    ->label('Warehouse')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('policy.min_quantity')
                    ->label('Min')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('policy.max_quantity')
                    ->label('Max')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('required_base_quantity')
                    ->label('Required')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('covered_base_quantity')
                    ->label('Covered')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('remaining_uncovered')
                    ->label('Uncovered')
                    ->state(fn (ReplenishmentRequirement $record): float => $record->remainingUncoveredQuantity())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('transfer_available')
                    ->label('Transfer Available')
                    ->state(fn (ReplenishmentRequirement $record): float => round(array_sum(array_map(
                        static fn ($suggestion): float => $suggestion->suggestedBaseQuantity,
                        app(ReplenishmentTransferSuggestionService::class)->suggest($record),
                    )), 6))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (ReplenishmentRequirementStatus $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('triggered_at')
                    ->label('Triggered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('Warehouse')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(ReplenishmentRequirementStatus::cases())
                        ->mapWithKeys(static fn (ReplenishmentRequirementStatus $status): array => [
                            $status->value => str($status->value)->headline()->toString(),
                        ])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
