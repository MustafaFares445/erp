<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Enums\ProductStatus;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

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
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->name])->all();
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
