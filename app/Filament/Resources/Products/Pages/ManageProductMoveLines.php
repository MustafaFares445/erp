<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ManageProductMoveLines extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'movements';

    protected static ?string $relatedResource = StockMovementResource::class;

    #[\Override]
    public function table(Table $table): Table
    {
        return StockMovementsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'productVariant:id,sku,name',
                'warehouse:id,code,name',
                'package:id,name',
                'createdBy:id,name',
            ]))
            ->recordActions([]);
    }
}
