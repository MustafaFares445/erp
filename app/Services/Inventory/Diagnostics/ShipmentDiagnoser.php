<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\InventoryOperation;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks a customer shipment against its delivery: a shipment enters transit only once its
 * delivery has left inventory (stage Done), and arrival can be confirmed only while it is in
 * transit with that delivery done ({@see Shipment::markInTransit()}, {@see Shipment::confirmByAdmin()}).
 */
final readonly class ShipmentDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    public function supports(Model $record): bool
    {
        return $record instanceof Shipment;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof Shipment);

        $record->loadMissing(['delivery', 'warehouse']);
        $delivery = $record->delivery;
        $findings = [];

        if (! $delivery instanceof InventoryOperation) {
            $findings[] = $this->finding('shipment.no_delivery', Category::DataQuality, Severity::Blocker,
                action: $this->guidance('escalate_inconsistency'));
        } else {
            $findings = [...$this->statusFindings($record, $delivery), ...$this->warehouseFindings($record, $delivery)];
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('shipment_delivery'),
                $this->check('shipment_status'),
                $this->check('shipment_warehouse'),
            ],
            limits: [
                $this->limit('shipment_physical'),
            ],
        );
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public function keyFigures(Shipment $shipment): array
    {
        $shipment->loadMissing('delivery');
        $delivery = $shipment->delivery;
        $dispatchedAt = $delivery?->completed_at;

        return [
            ['label' => __('inventory_view_ux.ui.metric_status'), 'value' => $shipment->status->label()],
            [
                'label' => __('inventory_view_ux.ui.metric_delivery'),
                'value' => $delivery instanceof InventoryOperation ? (string) ($delivery->operation_number ?? '#'.$delivery->id) : '—',
                'helper' => $delivery?->stageLabel(),
            ],
            [
                'label' => __('inventory_view_ux.ui.metric_days_in_transit'),
                'value' => $shipment->isInTransit() && $dispatchedAt !== null ? (string) $this->daysSince($dispatchedAt) : '—',
                'helper' => $dispatchedAt?->toDateString(),
            ],
            ['label' => __('inventory_view_ux.ui.metric_attachments'), 'value' => (string) $shipment->getMedia('attachments')->count()],
        ];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function statusFindings(Shipment $shipment, InventoryOperation $delivery): array
    {
        $number = (string) ($delivery->operation_number ?? '#'.$delivery->id);
        $replace = ['number' => $number, 'stage' => $delivery->stageLabel()];
        $openDelivery = $this->viewLink(InventoryOperationResource::class, $delivery, 'open_delivery', ['number' => $number]);

        return match ($shipment->status) {
            ShipmentStatus::Planned => [match ($delivery->stage) {
                OperationStage::Done => $this->finding('shipment.planned_delivery_done', Category::DataQuality, Severity::Warning, $replace,
                    action: $this->guidance('escalate_inconsistency')),
                OperationStage::Canceled => $this->finding('shipment.planned_delivery_canceled', Category::DataQuality, Severity::Warning, $replace,
                    action: $openDelivery),
                OperationStage::Ready => $this->finding('shipment.planned', Category::Delivery, Severity::Notice, $replace,
                    action: $openDelivery),
                default => $this->finding('shipment.planned_delivery_not_ready', Category::Delivery, Severity::Notice, $replace,
                    action: $openDelivery),
            }],
            ShipmentStatus::InTransit => [$delivery->isDone()
                ? $this->finding('shipment.in_transit', Category::Delivery, Severity::Notice,
                    [...$replace, 'days' => $delivery->completed_at !== null ? $this->daysSince($delivery->completed_at) : '—'],
                    facts: $delivery->completed_at !== null
                        ? [$this->fact('shipment_dispatched_at', ['date' => $delivery->completed_at->toDateTimeString()])]
                        : [],
                    action: $this->confirmStep($shipment))
                : $this->finding('shipment.in_transit_delivery_not_done', Category::DataQuality, Severity::Blocker, $replace,
                    action: $openDelivery)],
            ShipmentStatus::Arrived => [$shipment->confirmed_at === null || $shipment->confirmed_by_type === null
                ? $this->finding('shipment.arrival_unrecorded', Category::DataQuality, Severity::Warning,
                    action: $this->guidance('escalate_inconsistency'))
                : $this->finding('shipment.arrived', Category::Delivery, Severity::Notice,
                    ['date' => $shipment->confirmed_at->toDateTimeString(), 'by' => $shipment->confirmedByLabel() ?? '—'])],
            ShipmentStatus::Cancelled => [$delivery->isDone()
                ? $this->finding('shipment.cancelled_delivery_done', Category::DataQuality, Severity::Warning, $replace,
                    action: $openDelivery)
                : $this->finding('shipment.cancelled', Category::Delivery, Severity::Notice, $replace)],
        };
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function warehouseFindings(Shipment $shipment, InventoryOperation $delivery): array
    {
        if ($delivery->source_warehouse_id === null || $shipment->warehouse_id === $delivery->source_warehouse_id) {
            return [];
        }

        return [$this->finding('shipment.warehouse_mismatch', Category::DataQuality, Severity::Warning,
            facts: [$this->fact('shipment_warehouses', [
                'shipment' => $shipment->warehouse !== null ? $shipment->warehouse->name : '#'.$shipment->warehouse_id,
                'delivery' => '#'.$delivery->source_warehouse_id,
            ])],
        )];
    }

    private function confirmStep(Shipment $shipment): InventoryDiagnosticAction
    {
        $label = $this->actionLabel('shipment_confirm_arrival', ['button' => __('admin.shipment.actions.confirm')]);

        if (! (auth()->user()?->can('confirm', $shipment) ?? false)) {
            return new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
        }

        return InventoryDiagnosticAction::link($label, ShipmentResource::getUrl('view', ['record' => $shipment]), ShipmentResource::canView($shipment));
    }

    private function daysSince(\DateTimeInterface $moment): int
    {
        return max(0, (int) floor(now()->diffInDays($moment, true)));
    }
}
