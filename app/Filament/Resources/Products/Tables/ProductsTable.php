<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('main_image')
                    ->getStateUsing(static fn (Product $record): ?string => $record->mainImageUrl()),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('name_ar')->label('Arabic name')->searchable(),
                TextColumn::make('category.name')->searchable()->sortable(),
                TextColumn::make('brand.name')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('variants_count')->counts('variants')->label(__('admin.resources.product_variants')),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
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
