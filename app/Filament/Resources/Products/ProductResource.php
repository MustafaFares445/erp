<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ManageProductMoveLines;
use App\Filament\Resources\Products\Pages\ManageProductQuantities;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ManageProductVariants;
use App\Filament\Resources\Products\Pages\ManageProductVendors;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Services\Inventory\CountryNameResolver;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.products');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('name_ar')->label('Arabic name'),
                TextEntry::make('category.name'),
                TextEntry::make('brand.name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color())
                    ->helperText(static fn (ProductType $state): string => $state->description()),
                TextEntry::make('variants_count')
                    ->state(fn (Product $record): int => $record->variants()->count()),
                TextEntry::make('description')->columnSpanFull(),
                ImageEntry::make('images')
                    ->state(fn (Product $record): array => $record->getMedia('images')
                        ->map(static fn (Media $media): string => $media->getUrl('thumb'))
                        ->all())
                    ->imageHeight(120)
                    ->stacked(false)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'name_ar',
            'brand.name',
            'brand.name_ar',
            'category.name',
            'category.name_ar',
            'variants.name',
            'variants.name_ar',
            'variants.sku',
            'variants.barcode',
            'variants.supplierReferences.supplier.name',
            'variants.supplierReferences.supplier_name',
            'variants.supplierReferences.supplier_item_number',
            'variants.supplierReferences.manufacturer',
            'variants.supplierReferences.country_code',
        ];
    }

    #[\Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        return [
            'Brand' => $record->brand->name ?? 'No brand',
            'Category' => $record->category->name ?? 'No category',
        ];
    }

    /** @param Builder<Product> $query */
    #[\Override]
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->where(function (Builder $searchQuery) use ($search): void {
            parent::applyGlobalSearchAttributeConstraints($searchQuery, $search);
            $countryCodes = app(CountryNameResolver::class)->matchingCodes($search);

            if ($countryCodes !== []) {
                $searchQuery->orWhereHas(
                    'variants.supplierReferences',
                    fn (Builder $referenceQuery): Builder => $referenceQuery->whereIn('country_code', $countryCodes),
                );
            }
        });
    }

    /** @return array<NavigationItem> */
    #[\Override]
    public static function getRecordSubNavigation(Page $page): array
    {
        if (! $page instanceof ViewRecord && ! $page instanceof EditRecord && ! $page instanceof ManageRelatedRecords) {
            return [];
        }

        return array_merge(
            ViewProduct::getNavigationItems(['record' => $page->getRecord()]),
            EditProduct::getNavigationItems(['record' => $page->getRecord()]),
            ManageProductVariants::getNavigationItems(['record' => $page->getRecord()]),
            ManageProductVendors::getNavigationItems(['record' => $page->getRecord()]),
            ManageProductQuantities::getNavigationItems(['record' => $page->getRecord()]),
            ManageProductMoveLines::getNavigationItems(['record' => $page->getRecord()]),
        );
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
            'variants' => ManageProductVariants::route('/{record}/variants'),
            'vendors' => ManageProductVendors::route('/{record}/vendors'),
            'quantities' => ManageProductQuantities::route('/{record}/quantities'),
            'movements' => ManageProductMoveLines::route('/{record}/movements'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media');
    }
}
