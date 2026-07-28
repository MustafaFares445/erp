<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerPricingTiers\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class CustomerPricingTiersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.email')
                    ->label('Customer email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pricingTier.name')
                    ->label('General tier')
                    ->searchable(),
                TextColumn::make('pricingTier.discount_percent')
                    ->label('Discount')
                    ->suffix('%')
                    ->sortable(),
                ToggleColumn::make('is_active'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([TernaryFilter::make('is_active')])
            ->recordActions([ViewAction::make()]);
    }
}
