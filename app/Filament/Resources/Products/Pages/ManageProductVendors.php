<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ManageProductVendors extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'supplierProductReferences';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.name')->searchable()->sortable(),
            TextColumn::make('supplier_name')->label('Supplier product name')->searchable(),
            TextColumn::make('supplier_item_number')->label('Supplier product number')->searchable(),
            TextColumn::make('country_code')->label('Country'),
            TextColumn::make('purchase_cost')->money('USD')->visible(ProductVariantResource::canViewPricing()),
            TextColumn::make('currency_code')->visible(ProductVariantResource::canViewPricing()),
        ]);
    }
}
