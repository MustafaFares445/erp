<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides\Tables;

use App\Models\PriceFloorOverride;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PriceFloorOverridesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.name')->label('Variant')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->placeholder('General'),
                TextColumn::make('attempted_price')->money('USD')->sortable(),
                TextColumn::make('min_price')->label('Floor')->money('USD')->sortable(),
                TextColumn::make('approvedBy.name')->label('Approved by')->sortable(),
                TextColumn::make('approved_at')->dateTime()->sortable(),
                TextColumn::make('reason')->limit(60)->tooltip(fn (PriceFloorOverride $record): string => $record->reason ?? ''),
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
