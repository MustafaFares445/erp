<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductSupports;

use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Models\SupplierProductSupport;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

final class SupplierProductSupportResource extends Resource
{
    protected static ?string $model = SupplierProductSupport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.purchasing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload()->required(),
            Select::make('product_id')->relationship('product', 'name')->searchable()->preload()->live()
                ->disabled(fn (callable $get): bool => $get('product_variant_id') !== null),
            Select::make('product_variant_id')->relationship('productVariant', 'sku')->searchable()->preload()->live()
                ->disabled(fn (callable $get): bool => $get('product_id') !== null),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.name')->searchable()->sortable(),
            TextColumn::make('product.name')->label('Product')->placeholder('—')->searchable(),
            TextColumn::make('productVariant.sku')->label('Variant')->placeholder('—')->searchable(),
            ToggleColumn::make('is_active')->label('Active'),
        ])->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierProductSupports::route('/')];
    }
}
