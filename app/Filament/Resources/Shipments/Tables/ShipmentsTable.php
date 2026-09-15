<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments\Tables;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tracking_number')->label('Shipment #')->searchable()->sortable(),
                TextColumn::make('order.order_number')->label('Sales Order')->searchable()->sortable(),
                TextColumn::make('order.customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable(),
                TextColumn::make('delivery.operation_number')->label('Delivery')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (ShipmentStatus $state): string => $state->label()),
                TextColumn::make('delivery.dispatched_at')->label('Dispatched')->dateTime()->placeholder('—'),
                TextColumn::make('confirmed_at')->label('Arrived')->dateTime()->placeholder('—'),
                TextColumn::make('confirmed_by')->label('Confirmed by')
                    ->state(fn (Shipment $record): ?string => $record->confirmedByLabel())
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ShipmentStatus::cases())->mapWithKeys(
                        static fn (ShipmentStatus $status): array => [$status->value => $status->label()],
                    )->all(),
                ),
                SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label(__('admin.shipment.actions.confirm'))
                    ->visible(fn (Shipment $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                    ->authorize(fn (Shipment $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                    ->action(function (Shipment $record): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            app(ShipmentService::class)->confirmByAdmin($record, $user);
                        }
                    }),
            ]);
    }
}
