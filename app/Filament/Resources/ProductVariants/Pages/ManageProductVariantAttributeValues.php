<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ManageProductVariantAttributeValues extends ManageRelatedRecords
{
    protected static string $resource = ProductVariantResource::class;

    protected static string $relationship = 'attributeAssignments';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attributeValue.attribute.name')->label('Attribute')->sortable(),
            TextColumn::make('attributeValue.value')->label('Value')->searchable()->sortable(),
            TextColumn::make('variant.sku')->label('Variant')->searchable(),
        ]);
    }
}
