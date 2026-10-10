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
    public static function getRelationshipTitle(): string
    {
        return __('Attribute assignments');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ProductVariantResource::editAction()->label(__('Edit variant and attributes')),
        ];
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('attribute assignment'))
            ->pluralModelLabel(__('attribute assignments'))
            ->columns([
                TextColumn::make('attributeValue.attribute.name')->label(__('Attribute'))->sortable(),
                TextColumn::make('attributeValue.value')->label(__('Value'))->searchable()->sortable(),
                TextColumn::make('variant.sku')->label(__('Variant'))->searchable(),
            ]);
    }
}
