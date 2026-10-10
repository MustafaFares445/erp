<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductSupports;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductSupport;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Supplier capability is intentionally separate from the Supplier Catalog.
 *
 * Capability answers only whether a supplier can provide a product/variant.
 * Supplier item number, currency, and accepted purchase cost remain commercial
 * facts owned by SupplierProductReference.
 */
final class SupplierProductSupportResource extends Resource
{
    protected static ?string $model = SupplierProductSupport::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.supplier_product_supports');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('Supplier Capability');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('Supplier Capabilities');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label(__('Supplier'))
                ->options(fn (): array => Supplier::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->helperText(__('Capability is Purchasing-owned master data. Only active suppliers can receive new capability records.')),
            Select::make('product_id')
                ->label(__('Product-wide capability'))
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (Get $get): bool => $get('product_variant_id') === null)
                ->disabled(fn (Get $get): bool => $get('product_variant_id') !== null)
                ->helperText(__('Choose a product to indicate that the supplier can provide the product generally.')),
            Select::make('product_variant_id')
                ->label(__('Variant-specific capability'))
                ->relationship('productVariant', 'sku')
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (Get $get): bool => $get('product_id') === null)
                ->disabled(fn (Get $get): bool => $get('product_id') !== null)
                ->helperText(__('Use a specific variant when the supplier capability is limited to that variant.')),
            Toggle::make('is_active')
                ->label(__('Active capability'))
                ->helperText(__('Inactive capability remains as historical master data but is not used for new procurement selection.'))
                ->default(true),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label(__('Capability scope'))
                    ->state(fn (SupplierProductSupport $record): string => $record->product_variant_id === null
                        ? __('Product-wide')
                        : __('Variant-specific'))
                    ->badge()
                    ->color(fn (SupplierProductSupport $record): string => $record->product_variant_id === null ? 'info' : 'primary'),
                TextColumn::make('product_name')
                    ->label(__('Product'))
                    ->state(fn (SupplierProductSupport $record): ?string => self::productName($record))
                    ->placeholder(__('—'))
                    ->wrap()
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->where(
                        static fn (Builder $matches): Builder => $matches
                            ->whereHas('product', static fn (Builder $product): Builder => $product->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('productVariant.product', static fn (Builder $product): Builder => $product->where('name', 'like', "%{$search}%")),
                    )),
                TextColumn::make('productVariant.product.name')
                    ->label(__('Variant product'))
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productVariant.sku')
                    ->label(__('Variant'))
                    ->placeholder(__('All variants'))
                    ->searchable(),
                ToggleColumn::make('is_active')->label(__('Active')),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('Supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('is_active')->label(__('Active capability')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'product', 'productVariant.product']);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierProductSupports::route('/')];
    }

    /** A variant-specific capability has no product_id of its own; its product comes from the variant. */
    private static function productName(SupplierProductSupport $support): ?string
    {
        $product = $support->product;

        if ($product instanceof Product) {
            return $product->name;
        }

        $variantProduct = $support->productVariant?->product;

        return $variantProduct instanceof Product ? $variantProduct->name : null;
    }
}
