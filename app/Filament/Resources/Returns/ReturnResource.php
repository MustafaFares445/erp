<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns;

use App\Enums\MovementType;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Models\InventoryMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ReturnResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.returns');
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
            TextColumn::make('created_at')->label(__('admin.inventory.movement.date'))->dateTime()->sortable(),
            TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('productVariant.name')->label('Variant')->searchable(),
            TextColumn::make('warehouse.name')->searchable()->sortable(),
            TextColumn::make('quantity')->sortable(),
            TextColumn::make('source_type')->searchable(),
            TextColumn::make('source_id')->sortable(),
            TextColumn::make('createdBy.name')->label(__('admin.inventory.movement.creator')),
        ])->filters([
            SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
        ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('movement_type', MovementType::Return->value);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageReturns::route('/')];
    }
}
