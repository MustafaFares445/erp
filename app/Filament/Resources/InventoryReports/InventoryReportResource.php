<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports;

use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Models\InventoryStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryReportResource extends Resource
{
    protected static ?string $model = InventoryStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('productVariant.name')->label('Variant')->searchable()->sortable(),
            TextColumn::make('productVariant.product.brand.name')->label('Brand')->searchable(),
            TextColumn::make('productVariant.product.category.name')->label('Category')->searchable(),
            TextColumn::make('productVariant.status')->label('Status')->badge(),
            TextColumn::make('warehouse.name')->label('Warehouse')->searchable()->sortable(),
            TextColumn::make('on_hand_quantity')->label('On hand')->numeric(decimalPlaces: 3),
            TextColumn::make('available_quantity')->label('Available')->numeric(decimalPlaces: 3),
            TextColumn::make('productVariant.cost_price')->label('Cost')->money('USD'),
            TextColumn::make('productVariant.base_price')->label('Base price')->money('USD'),
        ])->filters([
            SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
        ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['productVariant.product.brand', 'productVariant.product.category', 'warehouse']);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryReports::route('/')];
    }
}
