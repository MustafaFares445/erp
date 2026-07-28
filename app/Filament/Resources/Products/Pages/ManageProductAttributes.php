<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ManageProductAttributes extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'productAttributeValues';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attributeValue.attribute.name')->label('Attribute'),
            TextColumn::make('attributeValue.value')->label('Value'),
            TextColumn::make('variant.sku')->label('Variant'),
        ]);
    }
}
