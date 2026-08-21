<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
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
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('images')
                    ->getStateUsing(static fn (Product $record): array => $record->getMedia('images')
                        ->map(static fn (Media $media): string => $media->getUrl('thumb'))
                        ->all())
                    ->imageHeight(40)
                    ->stacked()
                    ->wrap(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                TextColumn::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('category.name')->searchable()->sortable(),
                TextColumn::make('brand.name')->searchable()->sortable(),
                TextColumn::make('variants_count')->counts('variants')->label(__('admin.resources.product_variants_number')),
                ToggleColumn::make('is_active'),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->options(ProductType::options())
                    ->multiple(),
                SelectFilter::make('category_id')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())
            ->mapWithKeys(static fn (ProductStatus $status): array => [$status->value => $status->name])
            ->all();
    }
}
