<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PriceHistoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('cost_price')->money('USD')->sortable(),
                TextColumn::make('markup_percent')->suffix('%')->sortable(),
                TextColumn::make('base_price')->money('USD')->sortable(),
                TextColumn::make('min_price')->money('USD')->sortable(),
                TextColumn::make('changedBy.name')->label('Changed by')->sortable(),
                TextColumn::make('created_at')->label('Changed at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_variant_id')
                    ->label('Variant')
                    ->relationship('productVariant', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
