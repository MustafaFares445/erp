<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockReservations;

use App\Filament\Resources\StockReservations\Pages\ManageStockReservations;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class StockReservationResource extends Resource
{
    protected static ?string $model = StockReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

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
            TextColumn::make('productVariant.name')->label('Variant')->searchable(),
            TextColumn::make('warehouse.name')->searchable()->sortable(),
            TextColumn::make('quantity')->sortable(),
            TextColumn::make('source_type')->searchable(),
            TextColumn::make('source_id')->sortable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'released' => 'Released', 'expired' => 'Expired']),
            SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
        ])->recordActions([
            Action::make('release')
                ->color('warning')
                ->visible(fn (StockReservation $record): bool => $record->isReleasable() && (auth()->user()?->can('release', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (StockReservation $record): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(ReservationService::class)->release($record, $actor);
                    }
                }),
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageStockReservations::route('/')];
    }
}
