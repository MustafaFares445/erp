<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductSupports;

use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Models\Supplier;
use App\Models\SupplierProductSupport;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
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
        return 'Supplier Capability';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label('Supplier')
                ->options(fn (): array => Supplier::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Capability is Purchasing-owned master data. Only active suppliers can receive new capability records.'),
            Select::make('product_id')
                ->label('Product-wide capability')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (Get $get): bool => $get('product_variant_id') === null)
                ->disabled(fn (Get $get): bool => $get('product_variant_id') !== null)
                ->helperText('Choose a product to indicate that the supplier can provide the product generally.'),
            Select::make('product_variant_id')
                ->label('Variant-specific capability')
                ->relationship('productVariant', 'sku')
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (Get $get): bool => $get('product_id') === null)
                ->disabled(fn (Get $get): bool => $get('product_id') !== null)
                ->helperText('Use a specific variant when the supplier capability is limited to that variant.'),
            Toggle::make('is_active')
                ->label('Active capability')
                ->helperText('Inactive capability remains as historical master data but is not used for new procurement selection.')
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
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label('Capability scope')
                    ->state(fn (SupplierProductSupport $record): string => $record->product_variant_id === null
                        ? 'Product-wide'
                        : 'Variant-specific')
                    ->badge()
                    ->color(fn (SupplierProductSupport $record): string => $record->product_variant_id === null ? 'info' : 'primary'),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->placeholder('Derived from variant')
                    ->searchable(),
                TextColumn::make('productVariant.product.name')
                    ->label('Variant product')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productVariant.sku')
                    ->label('Variant')
                    ->placeholder('All variants')
                    ->searchable(),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('is_active')->label('Active capability'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierProductSupports::route('/')];
    }
}
