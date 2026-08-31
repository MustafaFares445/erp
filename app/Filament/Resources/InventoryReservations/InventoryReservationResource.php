<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations;

use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Models\InventoryReservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class InventoryReservationResource extends Resource
{
    protected static ?string $model = InventoryReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 303;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.reservations');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.name')->label('Variant')->searchable(),
                TextColumn::make('warehouse.name')->searchable()->sortable(),
                TextColumn::make('base_quantity')->label('Base Qty')->sortable(),
                TextColumn::make('allocations_count')->counts('allocations')->label('Allocations'),
                TextColumn::make('source_type')->label('Source')->searchable(),
                TextColumn::make('source_id')->label('Source ID')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ReservationStatus::Active->value => 'Active',
                    ReservationStatus::Consumed->value => 'Consumed',
                    ReservationStatus::Released->value => 'Released',
                    ReservationStatus::Expired->value => 'Expired',
                ]),
                SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
            ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['productVariant', 'warehouse'])
            ->withCount('allocations')
            ->latest();
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ListInventoryReservations::route('/')];
    }
}
