<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments\Tables;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ShipmentAttachmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereHas('media', fn (Builder $mediaQuery): Builder => $mediaQuery->where('collection_name', 'attachments'))
                ->with(['order.customer', 'warehouse', 'confirmedByAdminUser', 'confirmedByCustomer']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tracking_number')
                    ->label(__('admin.shipment.fields.tracking_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order.customer.company_name')
                    ->label(__('admin.shipment.fields.customer'))
                    ->searchable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.shipment.fields.warehouse'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.shipment.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ShipmentStatus $state): string => $state->label()),
                TextColumn::make('confirmed_by')
                    ->label(__('admin.shipment.fields.confirmed_by'))
                    ->state(fn (Shipment $record): ?string => $record->confirmedByLabel())
                    ->placeholder('-'),
                TextColumn::make('confirmed_at')
                    ->label(__('admin.shipment.fields.confirmed_at'))
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.shipment.fields.status'))
                    ->options(collect(ShipmentStatus::cases())->mapWithKeys(
                        static fn (ShipmentStatus $status): array => [$status->value => $status->label()],
                    )->all())
                    ->default(ShipmentStatus::InTransit->value),
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.shipment.fields.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('customer_id')
                    ->label(__('admin.shipment.fields.customer'))
                    ->relationship('order.customer', 'company_name')
                    ->searchable()
                    ->preload(),
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
