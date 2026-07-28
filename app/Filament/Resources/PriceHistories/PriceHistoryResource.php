<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories;

use App\Filament\Resources\PriceHistories\Pages\ListPriceHistories;
use App\Filament\Resources\PriceHistories\Pages\ViewPriceHistory;
use App\Filament\Resources\PriceHistories\Schemas\PriceHistoryInfolist;
use App\Filament\Resources\PriceHistories\Tables\PriceHistoriesTable;
use App\Models\PriceHistory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PriceHistoryResource extends Resource
{
    protected static ?string $model = PriceHistory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.price_histories');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PriceHistoryInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PriceHistoriesTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['productVariant:id,sku,name', 'changedBy:id,name'])
            ->latest('id');
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPriceHistories::route('/'),
            'view' => ViewPriceHistory::route('/{record}'),
        ];
    }
}
