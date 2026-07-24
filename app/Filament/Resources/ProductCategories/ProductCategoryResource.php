<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Resources\ProductCategories\Pages\ManageProductCategories;
use App\Models\ProductCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')->relationship('parent', 'name')->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            Toggle::make('is_active')->default(true),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('name_ar')->label('Arabic name')->searchable(),
            TextColumn::make('parent.name')->label('Parent')->searchable()->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageProductCategories::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
