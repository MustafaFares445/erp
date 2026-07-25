<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Enums\ProductStatus;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use App\Services\Inventory\CountryNameResolver;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
            Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
            Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required(),
            Textarea::make('description')->columnSpanFull(),
        ]);
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
                TextEntry::make('variants_count')
                    ->state(fn (Product $record): int => $record->variants()->count()),
                TextEntry::make('description')->columnSpanFull(),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->name])->all();
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
