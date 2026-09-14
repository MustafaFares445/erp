<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\RelationManagers;

use App\Data\Inventory\LogisticsInboundLineData;
use App\Filament\Resources\PurchaseInbounds\Actions\PurchaseInboundAllocationActions;
use App\Models\PurchaseInboundLine;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;

final class PurchaseInboundLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.logistics.inbound.lines_and_allocations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('sku')->label(__('admin.logistics.inbound.sku'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->sku),
                TextColumn::make('product')->label(__('admin.logistics.inbound.product'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->product),
                TextColumn::make('ordered')->label(__('admin.logistics.inbound.ordered'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->orderedBaseQuantity),
                TextColumn::make('confirmed')->label(__('admin.logistics.inbound.confirmed'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->confirmedBaseQuantity),
                TextColumn::make('backordered')->label(__('admin.logistics.inbound.backordered'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->backorderedBaseQuantity),
                TextColumn::make('allocated')->label(__('admin.logistics.inbound.allocated'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->allocatedBaseQuantity),
                TextColumn::make('received')->label(__('admin.logistics.inbound.received'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->receivedBaseQuantity),
                TextColumn::make('allocatable')->label(__('admin.logistics.inbound.still_allocatable'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->currentlyAllocatableBaseQuantity),
                TextColumn::make('next_action')->label(__('admin.logistics.fields.next_action'))
                    ->getStateUsing(fn (PurchaseInboundLine $record): string => self::projection($record)->nextAction),
            ])
            ->headerActions([])
            ->recordActions([
                PurchaseInboundAllocationActions::add(),
                PurchaseInboundAllocationActions::edit(),
                PurchaseInboundAllocationActions::remove(),
            ]);
    }

    private static function projection(PurchaseInboundLine $record): LogisticsInboundLineData
    {
        /** @var array<int, LogisticsInboundLineData> $cache */
        static $cache = [];

        return $cache[$record->id] ??= app(LogisticsInboundProjectionService::class)->projectLine($record);
    }
}
