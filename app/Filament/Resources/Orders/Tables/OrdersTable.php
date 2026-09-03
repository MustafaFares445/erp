<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('deliveries_count')->counts('deliveries')->label('Deliveries'),
                TextColumn::make('status')->badge(),
                TextColumn::make('reservation_coverage')
                    ->label('Stock coverage')
                    ->state(fn (\App\Models\Order $record): ?string => $record->hasLapsedReservations() ? 'Lapsed' : null)
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?OrderPaymentStatus $state): ?string => $state?->label()),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
