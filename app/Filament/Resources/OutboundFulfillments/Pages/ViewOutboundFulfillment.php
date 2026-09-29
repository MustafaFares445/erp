<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments\Pages;

use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\OutboundAvailabilityService;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Sales\OrderWorkflowService;
use App\Services\Sales\SalesProcurementRequirementService;
use App\Services\Shipments\ShipmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class ViewOutboundFulfillment extends ViewRecord
{
    protected static string $resource = OutboundFulfillmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('refreshAvailability')
                ->label(__('admin.inventory.outbound.actions.refresh_availability'))
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->action(function (Order $record): void {
                    $actor = $this->actor();
                    app(SalesProcurementRequirementService::class)->synchronize($record, $actor);
                    Notification::make()->success()->title(__('admin.inventory.outbound.notifications.refreshed'))->send();
                }),
            Action::make('createDeliveryPlan')
                ->label(__('admin.inventory.outbound.actions.create_delivery_plan'))
                ->icon(Heroicon::OutlinedMap)
                ->color(fn (Order $record): string => $record->deliveries()
                    ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value, OperationStage::Ready->value])
                    ->exists() ? 'gray' : 'primary')
                ->visible(fn (Order $record): bool => round(
                    app(OrderWorkflowService::class)->project($record)->remainingBase,
                    6,
                ) > 0.0)
                ->requiresConfirmation()
                ->modalDescription(__('admin.inventory.outbound.descriptions.plan'))
                ->action(function (Order $record): void {
                    $actor = $this->actor();
                    $shipments = app(OutboundAvailabilityService::class)->suggest($record);
                    if ($shipments === []) {
                        app(SalesProcurementRequirementService::class)->synchronize($record, $actor);
                        Notification::make()->warning()->title(__('admin.inventory.outbound.notifications.nothing_to_plan'))->send();

                        return;
                    }
                    app(OutboundFulfillmentService::class)->plan($actor, $record, $shipments);
                    app(SalesProcurementRequirementService::class)->synchronize($record->refresh(), $actor);
                    Notification::make()->success()->title(__('admin.inventory.outbound.notifications.planned'))->send();
                }),
            Action::make('prepareDelivery')
                ->label(__('admin.inventory.outbound.actions.prepare_delivery'))
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->visible(fn (Order $record): bool => $record->deliveries()
                    ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value])
                    ->exists())
                ->requiresConfirmation()
                ->modalDescription(__('admin.inventory.outbound.descriptions.prepare'))
                ->schema([
                    Select::make('delivery_id')
                        ->label(__('admin.inventory.outbound.fields.planned_delivery'))
                        ->options(fn (Order $record): array => $record->deliveries()
                            ->with('sourceWarehouse:id,name')
                            ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value])
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (InventoryOperation $delivery): array => [
                                $delivery->id => ($delivery->operation_number ?: __('admin.inventory.outbound.placeholders.draft').' #'.$delivery->id)
                                    .' — '.($delivery->sourceWarehouse->name ?? '#'.$delivery->source_warehouse_id),
                            ])->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $delivery = $record->deliveries()->findOrFail($this->integerInput($data['delivery_id'] ?? null));
                    app(OutboundFulfillmentService::class)->prepare($this->actor(), $delivery);
                    Notification::make()->success()->title(__('admin.inventory.outbound.notifications.prepared'))->send();
                }),
            Action::make('dispatchGoods')
                ->label(__('admin.inventory.outbound.actions.dispatch_goods'))
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->visible(fn (Order $record): bool => $record->deliveries()
                    ->where('stage', OperationStage::Ready->value)
                    ->exists())
                ->requiresConfirmation()
                ->modalDescription(__('admin.inventory.outbound.descriptions.dispatch'))
                ->schema([
                    Select::make('delivery_id')
                        ->label(__('admin.inventory.outbound.fields.ready_delivery'))
                        ->options(fn (Order $record): array => $record->deliveries()
                            ->with('sourceWarehouse:id,name')
                            ->where('stage', OperationStage::Ready->value)
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (InventoryOperation $delivery): array => [
                                $delivery->id => ($delivery->operation_number ?: __('admin.inventory.outbound.fields.delivery').' #'.$delivery->id)
                                    .' — '.($delivery->sourceWarehouse->name ?? '#'.$delivery->source_warehouse_id),
                            ])->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $delivery = $record->deliveries()->findOrFail($this->integerInput($data['delivery_id'] ?? null));
                    app(OutboundDispatchService::class)->dispatch($this->actor(), $delivery);
                    Notification::make()->success()->title(__('admin.inventory.outbound.notifications.dispatched'))->send();
                }),
            Action::make('confirmArrival')
                ->label(__('admin.inventory.outbound.actions.confirm_arrival'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (Order $record): bool => $record->shipments()
                    ->where('status', ShipmentStatus::InTransit->value)
                    ->exists())
                ->schema([
                    Select::make('shipment_id')
                        ->label(__('admin.inventory.outbound.fields.in_transit_shipment'))
                        ->options(fn (Order $record): array => $record->shipments()
                            ->where('status', ShipmentStatus::InTransit->value)
                            ->orderBy('id')
                            ->pluck('tracking_number', 'id')
                            ->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $shipment = $record->shipments()->findOrFail($this->integerInput($data['shipment_id'] ?? null));
                    $actor = $this->actor();
                    if (! $actor->can('confirm', $shipment)) {
                        throw new LogicException('You are not authorized to confirm shipment arrival.');
                    }
                    app(ShipmentService::class)->confirmByAdmin($shipment, $actor);
                    Notification::make()->success()->title(__('admin.inventory.outbound.notifications.arrived'))->send();
                }),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated Logistics user is required.');
        }

        return $actor;
    }

    private function integerInput(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new LogicException('A numeric record identifier is required.');
        }

        return (int) $value;
    }
}
