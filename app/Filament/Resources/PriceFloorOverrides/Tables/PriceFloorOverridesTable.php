<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceFloorOverrides\Tables;

use App\Filament\Tables\TableFit;
use App\Models\PriceFloorOverride;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PriceFloorOverridesTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.sku')->label(__('SKU'))->searchable()->sortable(),
                TextColumn::make('productVariant.name')->wrap()->label(__('Variant'))->searchable(),
                TextColumn::make('customer.name')->wrap()->label(__('Customer'))->placeholder(__('General')),
                TextColumn::make('pricingTier.name')->toggleable(isToggledHiddenByDefault: true)->wrap()->label(__('Pricing tier'))->placeholder(__('Base or manual price')),
                TextColumn::make('attempted_price')->money()->sortable(),
                TextColumn::make('min_price')->label(__('Floor'))->money()->sortable(),
                TextColumn::make('approvedBy.name')->wrap()->label(__('Approved by'))->sortable(),
                TextColumn::make('approved_at')->toggleable(isToggledHiddenByDefault: true)->dateTime()->sortable(),
                TextColumn::make('reason')->wrap()->limit(60)->tooltip(fn (PriceFloorOverride $record): string => $record->reason ?? ''),
            ])
            ->filters([
                SelectFilter::make('product_variant_id')
                    ->label(__('Variant'))
                    ->relationship('productVariant', 'name')
                    ->searchable(),
                SelectFilter::make('pricing_tier_id')
                    ->label(__('Pricing tier'))
                    ->relationship('pricingTier', 'name')
                    ->searchable(),
            ])
            ->recordActions(TableFit::rowActions([ViewAction::make()]));
    }
}
