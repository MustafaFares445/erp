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
                TextColumn::make('tracking_number')->label(__('admin.shipment.fields.shipment_number'))->searchable()->sortable(),
                TextColumn::make('order.order_number')->label(__('admin.shipment.fields.sales_order'))->searchable()->sortable(),
                TextColumn::make('order.customer.company_name')->label(__('admin.shipment.fields.customer'))->searchable(),
                TextColumn::make('warehouse.name')->label(__('admin.shipment.fields.warehouse'))->searchable(),
                TextColumn::make('delivery.operation_number')->label(__('admin.shipment.fields.delivery'))->placeholder(__('—')),
                TextColumn::make('status')->label(__('admin.inventory.customer_return_request.fields.status'))->badge()->formatStateUsing(fn (ShipmentStatus $state): string => __('admin.shipment.statuses.'.$state->value)),
                TextColumn::make('delivery.dispatched_at')->label(__('admin.shipment.fields.dispatched_at'))->dateTime()->placeholder(__('—')),
                TextColumn::make('confirmed_at')->label(__('admin.shipment.fields.arrived_at'))->dateTime()->placeholder(__('—')),
                TextColumn::make('confirmed_by')->label(__('admin.shipment.fields.confirmed_by'))
                    ->state(fn (Shipment $record): ?string => $record->confirmedByLabel())
                    ->placeholder(__('—')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.inventory.customer_return_request.fields.status'))->options(
                    collect(ShipmentStatus::cases())->mapWithKeys(
                        static fn (ShipmentStatus $status): array => [$status->value => __('admin.shipment.statuses.'.$status->value)],
                    )->all(),
                ),
                SelectFilter::make('warehouse_id')->label(__('admin.inventory.shipment.fields.warehouse'))->relationship('warehouse', 'name')->searchable()->preload(),
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
