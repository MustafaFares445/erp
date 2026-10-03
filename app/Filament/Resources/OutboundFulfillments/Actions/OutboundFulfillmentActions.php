<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboundFulfillments\Actions;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\Shipment;
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
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * Outbound fulfillment operations shared by the detail page header and the
 * work-queue table. Each factory takes `$tableRow`: when true the action is
 * the single, state-driven primary row action (visible only for the order's
 * next step and an authorized user); when false it keeps the detail page
 * behaviour of being available whenever the data allows.
 */
final class OutboundFulfillmentActions
{
    public const string STEP_SUPPLY = 'supply';

    public const string STEP_PLAN = 'plan';

    public const string STEP_PREPARE = 'prepare';

    public const string STEP_DISPATCH = 'dispatch';

    public const string STEP_ARRIVAL = 'arrival';

    /**
     * The single next outbound step for an order, or null when nothing
     * actionable remains (terminal or waiting on others). Reads the
     * eager-loaded deliveries and shipments so list rows stay cheap.
     */
    public static function nextStep(Order $order): ?string
    {
        $projection = app(OrderWorkflowService::class)->project($order);

        if ($projection->procurementOutstandingBase > 0.000001) {
            return self::STEP_SUPPLY;
        }

        if (round($projection->remainingBase, 6) > 0.0) {
            return self::STEP_PLAN;
        }

        $stages = $order->deliveries->map(static fn (InventoryOperation $delivery): OperationStage => $delivery->stage);

        if ($stages->contains(static fn (OperationStage $stage): bool => in_array($stage, [OperationStage::Draft, OperationStage::Waiting], true))) {
            return self::STEP_PREPARE;
        }

        if ($stages->contains(OperationStage::Ready)) {
            return self::STEP_DISPATCH;
        }

        if ($order->shipments->contains(static fn (Shipment $shipment): bool => $shipment->status === ShipmentStatus::InTransit)) {
            return self::STEP_ARRIVAL;
        }

        return null;
    }

    public static function reviewSupply(): Action
    {
        return Action::make('reviewSupply')
            ->label(__('Review supply requirement'))
            ->icon(Heroicon::OutlinedShoppingCart)
            ->button()
            ->color('warning')
            ->visible(fn (Order $record): bool => self::nextStep($record) === self::STEP_SUPPLY
                && (PurchaseNeeds::canAccess() || OutboundFulfillmentResource::canView($record)))
            ->url(fn (Order $record): string => PurchaseNeeds::canAccess()
                ? PurchaseNeeds::getUrl()
                : OutboundFulfillmentResource::getUrl('view', ['record' => $record]));
    }

    public static function refreshAvailability(): Action
    {
        return Action::make('refreshAvailability')
            ->label(__('admin.inventory.outbound.actions.refresh_availability'))
            ->icon(Heroicon::ArrowPath)
            ->color('gray')
            ->action(function (Order $record): void {
                app(SalesProcurementRequirementService::class)->synchronize($record, self::actor());
                Notification::make()->success()->title(__('admin.inventory.outbound.notifications.refreshed'))->send();
            });
    }

    public static function createDeliveryPlan(bool $tableRow = false): Action
    {
        $action = Action::make('createDeliveryPlan')
            ->label($tableRow ? __('Create delivery plan') : __('admin.inventory.outbound.actions.create_delivery_plan'))
            ->icon(Heroicon::OutlinedMap)
            ->requiresConfirmation()
            ->modalDescription(__('admin.inventory.outbound.descriptions.plan'))
            ->action(function (Order $record): void {
                $actor = self::actor();
                $shipments = app(OutboundAvailabilityService::class)->suggest($record);
                if ($shipments === []) {
                    app(SalesProcurementRequirementService::class)->synchronize($record, $actor);
                    Notification::make()->warning()->title(__('admin.inventory.outbound.notifications.nothing_to_plan'))->send();

                    return;
                }
                app(OutboundFulfillmentService::class)->plan($actor, $record, $shipments);
                app(SalesProcurementRequirementService::class)->synchronize($record->refresh(), $actor);
                Notification::make()->success()->title(__('admin.inventory.outbound.notifications.planned'))->send();
            });

        if ($tableRow) {
            return $action
                ->button()
                ->color('primary')
                ->visible(fn (Order $record): bool => self::nextStep($record) === self::STEP_PLAN
                    && (self::user()?->can('planFulfillment', $record) ?? false));
        }

        return $action
            ->color(fn (Order $record): string => $record->deliveries()
                ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value, OperationStage::Ready->value])
                ->exists() ? 'gray' : 'primary')
            ->visible(fn (Order $record): bool => round(
                app(OrderWorkflowService::class)->project($record)->remainingBase,
                6,
            ) > 0.0);
    }

    public static function prepareDelivery(bool $tableRow = false): Action
    {
        $action = Action::make('prepareDelivery')
            ->label($tableRow ? __('Prepare delivery') : __('admin.inventory.outbound.actions.prepare_delivery'))
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
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
                $delivery = $record->deliveries()->findOrFail(self::integerInput($data['delivery_id'] ?? null));
                app(OutboundFulfillmentService::class)->prepare(self::actor(), $delivery);
                Notification::make()->success()->title(__('admin.inventory.outbound.notifications.prepared'))->send();
            });

        if ($tableRow) {
            return $action
                ->button()
                ->color('primary')
                ->visible(fn (Order $record): bool => self::nextStep($record) === self::STEP_PREPARE
                    && self::canOnDelivery($record, [OperationStage::Draft, OperationStage::Waiting], 'markReady'));
        }

        return $action->visible(fn (Order $record): bool => $record->deliveries()
            ->whereIn('stage', [OperationStage::Draft->value, OperationStage::Waiting->value])
            ->exists());
    }

    public static function dispatchGoods(bool $tableRow = false): Action
    {
        $action = Action::make('dispatchGoods')
            ->label(__('admin.inventory.outbound.actions.dispatch_goods'))
            ->icon(Heroicon::OutlinedTruck)
            ->color('success')
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
                $delivery = $record->deliveries()->findOrFail(self::integerInput($data['delivery_id'] ?? null));
                app(OutboundDispatchService::class)->dispatch(self::actor(), $delivery);
                Notification::make()->success()->title(__('admin.inventory.outbound.notifications.dispatched'))->send();
            });

        if ($tableRow) {
            return $action
                ->button()
                ->visible(fn (Order $record): bool => self::nextStep($record) === self::STEP_DISPATCH
                    && self::canOnDelivery($record, [OperationStage::Ready], 'complete'));
        }

        return $action->visible(fn (Order $record): bool => $record->deliveries()
            ->where('stage', OperationStage::Ready->value)
            ->exists());
    }

    public static function confirmArrival(bool $tableRow = false): Action
    {
        $action = Action::make('confirmArrival')
            ->label($tableRow ? __('Confirm arrival') : __('admin.inventory.outbound.actions.confirm_arrival'))
            ->icon(Heroicon::OutlinedCheckBadge)
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
                $shipment = $record->shipments()->findOrFail(self::integerInput($data['shipment_id'] ?? null));
                $actor = self::actor();
                if (! $actor->can('confirm', $shipment)) {
                    throw new LogicException('You are not authorized to confirm shipment arrival.');
                }
                app(ShipmentService::class)->confirmByAdmin($shipment, $actor);
                Notification::make()->success()->title(__('admin.inventory.outbound.notifications.arrived'))->send();
            });

        if ($tableRow) {
            return $action
                ->button()
                ->color('primary')
                ->visible(fn (Order $record): bool => self::nextStep($record) === self::STEP_ARRIVAL
                    && (self::user()?->can(InventoryPermission::ShipmentConfirm->value) ?? false));
        }

        return $action->visible(fn (Order $record): bool => $record->shipments()
            ->where('status', ShipmentStatus::InTransit->value)
            ->exists());
    }

    /** @param list<OperationStage> $stages */
    private static function canOnDelivery(Order $order, array $stages, string $ability): bool
    {
        $user = self::user();

        if (! $user instanceof User) {
            return false;
        }

        $delivery = $order->deliveries->first(static fn (InventoryOperation $delivery): bool => in_array($delivery->stage, $stages, true));

        return $delivery instanceof InventoryOperation && $user->can($ability, $delivery);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function actor(): User
    {
        return self::user() ?? throw new LogicException('An authenticated Logistics user is required.');
    }

    private static function integerInput(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new LogicException('A numeric record identifier is required.');
        }

        return (int) $value;
    }
}
