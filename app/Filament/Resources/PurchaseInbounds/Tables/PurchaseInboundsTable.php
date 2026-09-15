<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Tables;

use App\Data\Inventory\LogisticsInboundData;
use App\Enums\PurchaseInboundStatus;
use App\Models\PurchaseInbound;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Support\QuantityFormatter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseInboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label(__('admin.logistics.inbound.number'))->prefix('INB-')->sortable(),
                TextColumn::make('purchaseOrder.purchase_order_number')
                    ->label(__('admin.logistics.inbound.purchase_order_reference'))->searchable()->sortable(),
                TextColumn::make('purchaseOrder.supplier.name')
                    ->label(__('admin.logistics.inbound.supplier'))->searchable(),
                TextColumn::make('purchaseOrder.expected_at')
                    ->label(__('admin.logistics.inbound.expected_date'))->date()->placeholder('—')->sortable(),
                TextColumn::make('business_state')
                    ->label(__('admin.logistics.inbound.business_state'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => self::projection($record)->businessState)
                    ->badge()
                    ->color(fn (PurchaseInbound $record): string => self::stateColor(self::projection($record)->businessState)),
                TextColumn::make('confirmed_qty')
                    ->label(__('admin.logistics.inbound.confirmed'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->confirmedBaseQuantity)),
                TextColumn::make('allocated_qty')
                    ->label(__('admin.logistics.inbound.allocated'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->allocatedBaseQuantity)),
                TextColumn::make('received_qty')
                    ->label(__('admin.logistics.inbound.received'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->receivedBaseQuantity)),
                TextColumn::make('remaining_qty')
                    ->label(__('admin.logistics.inbound.remaining'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => QuantityFormatter::display(self::projection($record)->remainingBaseQuantity)),
                TextColumn::make('warehouses')
                    ->label(__('admin.logistics.inbound.destination_warehouses'))
                    ->getStateUsing(fn (PurchaseInbound $record): array => self::projection($record)->destinationWarehouses)
                    ->listWithLineBreaks()->placeholder('—'),
                IconColumn::make('overdue')
                    ->label(__('admin.logistics.inbound.overdue'))
                    ->boolean()
                    ->getStateUsing(fn (PurchaseInbound $record): bool => self::projection($record)->overdue),
                TextColumn::make('next_action')
                    ->label(__('admin.logistics.fields.next_action'))
                    ->getStateUsing(fn (PurchaseInbound $record): string => self::projection($record)->nextAction),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        PurchaseInboundStatus::values(),
                        array_map(static fn (string $value): string => str($value)->replace('_', ' ')->title()->toString(), PurchaseInboundStatus::values()),
                    )),
                Filter::make('overdue')
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereHas('purchaseOrder', static fn (Builder $po): Builder => $po->whereDate('expected_at', '<', today()))
                        ->where('status', '!=', PurchaseInboundStatus::Received->value)),
            ])
            ->recordActions([ViewAction::make()]);
    }

    private static function projection(PurchaseInbound $record): LogisticsInboundData
    {
        /** @var array<int, LogisticsInboundData> $cache */
        static $cache = [];

        return $cache[$record->id] ??= app(LogisticsInboundProjectionService::class)->project($record);
    }

    private static function stateColor(string $state): string
    {
        return match ($state) {
            'Awaiting Allocation' => 'warning',
            'Ready to Receive' => 'info',
            'Partially Received' => 'primary',
            'Received' => 'success',
            'Cancelled / Closed' => 'gray',
            default => 'gray',
        };
    }
}
