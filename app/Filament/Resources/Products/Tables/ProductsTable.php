<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductOperationalProfile;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Tables\TableFit;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return TableFit::apply($table)
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('images')
                    ->getStateUsing(static fn (Product $record): array => $record->getMedia('images')
                        ->map(static fn (Media $media): string => $media->getUrl('thumb'))
                        ->all())
                    ->imageHeight(40)
                    ->stacked()
                    ->wrap(),
                TextColumn::make('name')->wrap()->searchable()->sortable(),
                TextColumn::make('name_ar')->wrap()->label(__('Arabic name'))->searchable(),
                TextColumn::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('category.name')->wrap()->searchable()->sortable(),
                TextColumn::make('manufacturer.name')->toggleable(isToggledHiddenByDefault: true)->wrap()->label(__('Manufacturer'))->searchable()->sortable(),
                TextColumn::make('brand.name')->toggleable(isToggledHiddenByDefault: true)->wrap()->searchable()->sortable(),
                TextColumn::make('operational_profile')
                    ->label(__('Profile'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductOperationalProfile $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('variants_count')->counts('variants')->label(__('Variants')),
                ToggleColumn::make('is_active')->label(__('Active')),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->options(ProductType::options())
                    ->multiple(),
                SelectFilter::make('operational_profile')->options(ProductOperationalProfile::options()),
                SelectFilter::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('manufacturer_id')->relationship('manufacturer', 'name')->searchable()->preload(),
                SelectFilter::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions(TableFit::rowActions([ViewAction::make(), EditAction::make(), DeleteAction::make(), RestoreAction::make()]));
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())
            ->mapWithKeys(static fn (ProductStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
