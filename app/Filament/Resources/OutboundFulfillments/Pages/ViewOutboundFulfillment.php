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
                ->label('Refresh Availability')
                ->icon(Heroicon::ArrowPath)
                ->action(function (Order $record): void {
                    $actor = $this->actor();
                    app(SalesProcurementRequirementService::class)->synchronize($record, $actor);
                    Notification::make()->success()->title('Availability and supply blockers refreshed.')->send();
                }),
            Action::make('createDeliveryPlan')
                ->label('Create Delivery Plan')
                ->icon(Heroicon::OutlinedMap)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Allocate currently available stock only. The plan creates Draft Deliveries and Planned Shipments; it does not reserve or move stock.')
                ->action(function (Order $record): void {
                    $actor = $this->actor();
                    $shipments = app(OutboundAvailabilityService::class)->suggest($record);
                    if ($shipments === []) {
                        app(SalesProcurementRequirementService::class)->synchronize($record, $actor);
                        Notification::make()->warning()->title('No currently available stock can be planned. Supply blockers were refreshed.')->send();

                        return;
                    }
                    app(OutboundFulfillmentService::class)->plan($actor, $record, $shipments);
                    app(SalesProcurementRequirementService::class)->synchronize($record->refresh(), $actor);
                    Notification::make()->success()->title('Delivery plan created. Reserve & Prepare when the warehouse is ready.')->send();
                }),
            Action::make('prepareDelivery')
                ->label('Reserve & Prepare')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->schema([
                    Select::make('delivery_id')
                        ->label('Planned delivery')
                        ->options(fn (Order $record): array => $record->deliveries()
                            ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value])
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (InventoryOperation $delivery): array => [
                                $delivery->id => ($delivery->operation_number ?: 'Draft #'.$delivery->id).' — warehouse #'.$delivery->source_warehouse_id,
                            ])->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $delivery = $record->deliveries()->findOrFail((int) $data['delivery_id']);
                    app(OutboundFulfillmentService::class)->prepare($this->actor(), $delivery);
                    Notification::make()->success()->title('Stock reserved and delivery prepared.')->send();
                }),
            Action::make('dispatchGoods')
                ->label('Dispatch Goods')
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->schema([
                    Select::make('delivery_id')
                        ->label('Ready delivery')
                        ->options(fn (Order $record): array => $record->deliveries()
                            ->where('stage', OperationStage::Ready->value)
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(fn (InventoryOperation $delivery): array => [
                                $delivery->id => ($delivery->operation_number ?: 'Delivery #'.$delivery->id).' — warehouse #'.$delivery->source_warehouse_id,
                            ])->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $delivery = $record->deliveries()->findOrFail((int) $data['delivery_id']);
                    app(OutboundDispatchService::class)->dispatch($this->actor(), $delivery);
                    Notification::make()->success()->title('Goods dispatched. Stock changed and shipment is now In Transit.')->send();
                }),
            Action::make('confirmArrival')
                ->label('Confirm Arrival')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->schema([
                    Select::make('shipment_id')
                        ->label('In-transit shipment')
                        ->options(fn (Order $record): array => $record->shipments()
                            ->where('status', ShipmentStatus::InTransit->value)
                            ->orderBy('id')
                            ->pluck('tracking_number', 'id')
                            ->all())
                        ->required(),
                ])
                ->action(function (Order $record, array $data): void {
                    $shipment = $record->shipments()->findOrFail((int) $data['shipment_id']);
                    $actor = $this->actor();
                    if (! $actor->can('confirm', $shipment)) {
                        throw new LogicException('You are not authorized to confirm shipment arrival.');
                    }
                    app(ShipmentService::class)->confirmByAdmin($shipment, $actor);
                    Notification::make()->success()->title('Shipment arrival confirmed. Warranty activation was evaluated.')->send();
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
}
