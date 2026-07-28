<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockLevels\Tables\StockLevelsTable;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ManageProductQuantities extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'stocks';

    protected static ?string $relatedResource = StockLevelResource::class;

    #[\Override]
    public function table(Table $table): Table
    {
        return StockLevelsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => StockLevelResource::withInTransitQuantity($query)
                ->with([
                    'productVariant:id,sku,name',
                    'warehouse:id,code,name',
                ]))
            ->recordActions([]);
    }
}
