<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockCondition;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\StockAvailabilityExplainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains where a Receipt, Delivery or Internal Transfer stands and what will stop its next
 * transition. Mirrors the guards of {@see InventoryOperationService} read-only: the stock
 * shortfall that holds an operation at Waiting (recomputed per SKU because the service stores no
 * reason), the tracking rules Mark Ready enforces, the per-line reservations completion consumes,
 * and the planned shipment customer dispatch requires.
 */
final readonly class InventoryOperationDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Epsilon = 0.000001;

    /** Short SKUs explained in detail (each one costs a few explainer queries). */
    private const int ExplainedShortages = 3;

    /** Lines quoted as facts before the list is truncated. */
    private const int QuotedLines = 8;

    public function __construct(
        private InventoryOperationService $operations,
        private StockAvailabilityExplainer $explainer,
    ) {}

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryOperation;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryOperation);

        $this->load($record);

        $findings = match ($record->stage) {
            OperationStage::Draft, OperationStage::Waiting => $this->openFindings($record),
            OperationStage::Ready => $this->readyFindings($record),
            OperationStage::InTransit, OperationStage::PartiallyReceived => $this->transitFindings($record),
            OperationStage::Done => $this->doneFindings($record),
            OperationStage::Canceled => [$this->finding('operation.canceled', Category::Workflow, Severity::Notice,
                ['date' => $record->canceled_at?->toDateTimeString() ?? '—'])],
        };

        $findings = [...$findings, ...$this->strayReservationFindings($record)];

        $limits = [$this->limit('recorded_balances'), $this->limit('operation_physical')];

        if ($record->isDraft() && $this->lines($record)->contains(static fn (InventoryOperationLine $line): bool => $line->base_quantity === null)) {
            $limits[] = $this->limit('operation_draft_units');
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('operation_lines'),
                $this->check('operation_shortage'),
                $this->check('operation_tracking'),
                $this->check('operation_reservations'),
                $this->check('operation_shipment'),
                $this->check('operation_transfer_receipt'),
                $this->check('operation_posting'),
            ],
            limits: $limits,
        );
    }

    /**
     * The Key figures strip of the operation View page.
     *
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public function keyFigures(InventoryOperation $operation): array
    {
        $this->load($operation);
        $lines = $this->lines($operation);

        $figures = [
            ['label' => __('inventory_view_ux.ui.metric_lines'), 'value' => (string) $lines->count()],
            ['label' => __('inventory_view_ux.ui.metric_demand'), ...$this->demandFigure($lines)],
        ];

        $outbound = $operation->operation_type !== OperationType::Receipt;

        if ($outbound && in_array($operation->stage, [OperationStage::Draft, OperationStage::Waiting], true)) {
            $short = count($this->shortages($operation));
            $figures[] = [
                'label' => __('inventory_view_ux.ui.metric_short_items'),
                'value' => (string) $short,
                'tone' => $short > 0 ? 'danger' : null,
            ];
        }

        if ($outbound && $operation->isReady() && $lines->isNotEmpty()) {
            $reserved = $lines->count() - count($this->linesWithoutActiveReservation($operation));
            $figures[] = [
                'label' => __('inventory_view_ux.ui.metric_reserved'),
                'value' => $this->percent($reserved, $lines->count()),
                'helper' => __('inventory_view_ux.ui.metric_reserved_helper', ['reserved' => $reserved, 'total' => $lines->count()]),
                'tone' => $reserved < $lines->count() ? 'danger' : null,
            ];
        }

        if ($operation->operation_type === OperationType::InternalTransfer
            && in_array($operation->stage, [OperationStage::InTransit, OperationStage::PartiallyReceived, OperationStage::Done], true)) {
            $dispatched = (float) $lines->sum(static fn (InventoryOperationLine $line): float => (float) $line->dispatched_base_quantity);
            $received = (float) $lines->sum(static fn (InventoryOperationLine $line): float => (float) $line->received_base_quantity);
            $figures[] = [
                'label' => __('inventory_view_ux.ui.metric_received'),
                'value' => $dispatched > 0 ? (string) round($received / $dispatched * 100).'%' : '—',
                'helper' => __('inventory_view_ux.ui.metric_received_helper', [
                    'received' => $this->quantity($received),
                    'dispatched' => $this->quantity($dispatched),
                ]),
                'tone' => $operation->isDone() || $received + self::Epsilon >= $dispatched ? null : 'warning',
            ];

            if (! $operation->isDone() && $operation->dispatched_at !== null) {
                $figures[] = [
                    'label' => __('inventory_view_ux.ui.metric_days_in_transit'),
                    'value' => (string) $this->daysSince($operation->dispatched_at),
                    'helper' => $operation->dispatched_at->toDateString(),
                ];
            }
        }

        if ($operation->isDone()) {
            $figures[] = [
                'label' => __('inventory_view_ux.ui.metric_movements'),
                'value' => (string) $operation->movements()->count(),
            ];
        }

        return $figures;
    }

    /**
     * Recomputes, read-only, the per-SKU check {@see InventoryOperationService::markReady()} uses to
     * hold an outbound operation at Waiting: non-serialized lines are summed per variant and compared
     * with the source warehouse's available quantity; a serialized line only needs a stock row.
     *
     * @return list<array{variant: ProductVariant, required: float, available: float, short: float, serialized: bool, stock: bool}>
     */
    public function shortages(InventoryOperation $operation): array
    {
        $warehouseId = $operation->source_warehouse_id;
        $lines = $this->lines($operation);

        if ($operation->operation_type === OperationType::Receipt || ! is_int($warehouseId) || $lines->isEmpty()) {
            return [];
        }

        /** @var list<int> $variantIds */
        $variantIds = array_values($lines->map(static fn (InventoryOperationLine $line): int => $line->product_variant_id)->unique()->all());
        $available = $this->operations->availableQuantitiesFor($variantIds, $warehouseId);
        $shortages = [];

        foreach ($lines->whereNull('serialized_inventory_unit_id')->groupBy('product_variant_id') as $variantLines) {
            /** @var InventoryOperationLine $first */
            $first = $variantLines->first();
            $variant = $first->productVariant;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $required = round((float) $variantLines->sum(fn (InventoryOperationLine $line): float => $this->lineQuantity($line)), 6);
            $availableQuantity = $available[$first->product_variant_id]['available_quantity'] ?? 0.0;

            if ($availableQuantity + self::Epsilon < $required) {
                $shortages[] = [
                    'variant' => $variant,
                    'required' => $required,
                    'available' => $availableQuantity,
                    'short' => round($required - $availableQuantity, 6),
                    'serialized' => false,
                    'stock' => array_key_exists($first->product_variant_id, $available),
                ];
            }
        }

        foreach ($lines->whereNotNull('serialized_inventory_unit_id')->groupBy('product_variant_id') as $variantLines) {
            /** @var InventoryOperationLine $first */
            $first = $variantLines->first();

            $variant = $first->productVariant;

            if ($variant instanceof ProductVariant && ! array_key_exists($first->product_variant_id, $available)) {
                $shortages[] = [
                    'variant' => $variant,
                    'required' => (float) $variantLines->count(),
                    'available' => 0.0,
                    'short' => (float) $variantLines->count(),
                    'serialized' => true,
                    'stock' => false,
                ];
            }
        }

        return $shortages;
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function openFindings(InventoryOperation $operation): array
    {
        $lines = $this->lines($operation);

        if ($lines->isEmpty()) {
            return [$this->finding('operation.no_lines', Category::Workflow, Severity::Blocker,
                action: $operation->isDraft()
                    ? InventoryDiagnosticAction::link(
                        $this->actionLabel('operation_edit_lines'),
                        InventoryOperationResource::getUrl('edit', ['record' => $operation]),
                        InventoryOperationResource::canEdit($operation),
                    )
                    : $this->guidance('operation_recreate'),
            )];
        }

        $findings = [
            ...$this->inactiveVariantFindings($operation),
            ...$this->trackingFindings($operation),
        ];

        $shortages = $this->shortages($operation);

        if ($shortages !== []) {
            $findings[] = $this->shortageFinding($operation, $shortages);
        } elseif ($operation->isWaiting()) {
            $findings[] = $this->finding('operation.stock_now_available', Category::Availability, Severity::Notice,
                ['warehouse' => $this->warehouseLabel($operation->sourceWarehouse, $operation->source_warehouse_id)],
                action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.mark_ready', $this->can('markReady', $operation)),
            );
        }

        if ($operation->isDraft() && $findings === []) {
            $findings[] = $this->finding('operation.draft_'.($operation->operation_type === OperationType::Receipt ? 'receipt' : 'outbound'),
                Category::Workflow, Severity::Notice,
                ['count' => $lines->count()],
                action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.mark_ready', $this->can('markReady', $operation)),
            );
        }

        return $findings;
    }

    /**
     * @param  non-empty-list<array{variant: ProductVariant, required: float, available: float, short: float, serialized: bool, stock: bool}>  $shortages
     */
    private function shortageFinding(InventoryOperation $operation, array $shortages): InventoryDiagnosticFinding
    {
        $warehouse = $operation->sourceWarehouse;
        $warehouseLabel = $this->warehouseLabel($warehouse, $operation->source_warehouse_id);
        $facts = [];

        foreach ($shortages as $index => $shortage) {
            $variant = $shortage['variant'];
            $unit = $this->unitSymbol($variant);
            $sku = $variant->sku;

            $facts[] = $shortage['serialized']
                ? $this->fact('op_serial_no_balance', ['sku' => $sku, 'warehouse' => $warehouseLabel])
                : $this->fact('op_shortage', [
                    'sku' => $sku,
                    'required' => $this->quantity($shortage['required'], $unit),
                    'available' => $this->quantity($shortage['available'], $unit),
                    'warehouse' => $warehouseLabel,
                    'short' => $this->quantity($shortage['short'], $unit),
                ]);

            if ($index >= self::ExplainedShortages || ! $warehouse instanceof Warehouse) {
                continue;
            }

            $explanation = $this->explainer->explain($variant, $warehouse);

            foreach ($explanation['causes'] as $cause) {
                $facts[] = $this->fact('op_shortage_cause', [
                    'sku' => $sku,
                    'cause' => $cause['label'],
                    'quantity' => $this->quantity($cause['quantity'], $unit),
                ]);
            }

            if ($explanation['in_transit']['quantity'] > 0) {
                $facts[] = $this->fact('op_shortage_in_transit', [
                    'sku' => $sku,
                    'quantity' => $this->quantity($explanation['in_transit']['quantity'], $unit),
                    'warehouse' => $warehouseLabel,
                ]);
            }
        }

        $first = $shortages[0];
        $stock = $first['stock']
            ? InventoryStock::query()
                ->where('product_variant_id', $first['variant']->getKey())
                ->where('warehouse_id', $operation->source_warehouse_id)
                ->first()
            : null;

        $replace = ['count' => count($shortages), 'warehouse' => $warehouseLabel];

        return $operation->isWaiting()
            ? $this->finding('operation.waiting_shortage', Category::Availability, Severity::Blocker, $replace, $facts,
                $this->stockAction($stock, $first['variant']))
            : $this->finding('operation.draft_shortage', Category::Availability, Severity::Warning, $replace, $facts,
                $this->stockAction($stock, $first['variant']));
    }

    private function stockAction(?InventoryStock $stock, ProductVariant $variant): InventoryDiagnosticAction
    {
        return $stock instanceof InventoryStock
            ? $this->viewLink(StockLevelResource::class, $stock, 'operation_open_stock', ['sku' => $variant->sku])
            : $this->guidance('operation_bring_stock');
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function inactiveVariantFindings(InventoryOperation $operation): array
    {
        $inactive = $this->lines($operation)->filter(static fn (InventoryOperationLine $line): bool => $line->productVariant?->isOperational() !== true);

        if ($inactive->isEmpty()) {
            return [];
        }

        return [$this->finding('operation.inactive_variant', Category::DataQuality, Severity::Warning,
            ['count' => $inactive->count()],
            facts: $this->lineFacts($operation, $inactive),
            action: $this->guidance('operation_inactive_variant'),
        )];
    }

    /**
     * The tracking rules {@see InventoryOperationService::markReady()} rejects with an error rather
     * than a Waiting stage (ProductTypeGuard, InventoryReservationService lot validation,
     * InventoryLotService expired-lot block).
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function trackingFindings(InventoryOperation $operation): array
    {
        $lines = $this->lines($operation);
        $receipt = $operation->operation_type === OperationType::Receipt;
        $findings = [];

        $rules = [
            'operation.serial_missing' => $lines->filter(static fn (InventoryOperationLine $line): bool => $line->productVariant?->tracksSerialsConfigured() === true
                && $line->serialized_inventory_unit_id === null),
            'operation.expiry_missing' => $receipt
                ? $lines->filter(static fn (InventoryOperationLine $line): bool => $line->productVariant?->tracksExpirationConfigured() === true && $line->expires_at === null)
                : new Collection,
            'operation.expiry_past' => $receipt
                ? $lines->filter(static fn (InventoryOperationLine $line): bool => $line->productVariant?->tracksExpirationConfigured() === true
                    && $line->expires_at !== null
                    && $line->expires_at->toDateString() < today()->toDateString())
                : new Collection,
            'operation.lot_missing' => $receipt
                ? new Collection
                : $lines->filter(static fn (InventoryOperationLine $line): bool => $line->productVariant?->tracksLotsConfigured() === true && $line->inventory_lot_id === null),
        ];

        foreach ($rules as $code => $affected) {
            if ($affected->isNotEmpty()) {
                $findings[] = $this->finding($code, Category::Tracking, Severity::Warning,
                    ['count' => $affected->count()],
                    facts: $this->lineFacts($operation, $affected),
                    action: $this->correctLinesAction($operation),
                );
            }
        }

        if ($receipt) {
            return $findings;
        }

        $expiredLots = $lines->filter(static fn (InventoryOperationLine $line): bool => $line->lot instanceof InventoryLot && $line->lot->expiryState() === 'expired');

        if ($expiredLots->isNotEmpty()) {
            $mayOverride = auth()->user()?->can(InventoryPermission::ExpiredStockOverride->value) ?? false;
            $findings[] = $this->finding($mayOverride ? 'operation.lot_expired_override' : 'operation.lot_expired', Category::Expiry, Severity::Warning,
                ['count' => $expiredLots->count()],
                facts: array_values($expiredLots->map(fn (InventoryOperationLine $line): string => $this->fact('op_line_lot_expired', [
                    'sku' => $this->sku($line),
                    'lot' => (string) ($line->lot->lot_number ?? '#'.$line->inventory_lot_id),
                    'date' => $line->lot->expires_at?->toDateString() ?? '—',
                ]))->all()),
                action: $this->correctLinesAction($operation),
            );
        }

        $unavailableSerials = $lines->filter(fn (InventoryOperationLine $line): bool => $line->serializedUnit instanceof SerializedInventoryUnit
            && ! $this->serialIsSaleableAt($line, $line->serializedUnit, $operation->source_warehouse_id));

        if ($unavailableSerials->isNotEmpty()) {
            $findings[] = $this->finding('operation.serial_unavailable', Category::Tracking, Severity::Warning,
                ['count' => $unavailableSerials->count()],
                facts: array_values($unavailableSerials->map(function (InventoryOperationLine $line): string {
                    /** @var SerializedInventoryUnit $unit */
                    $unit = $line->serializedUnit;

                    return $this->fact('op_line_serial_state', [
                        'sku' => $this->sku($line),
                        'serial' => $unit->serial_number,
                        'status' => $unit->status->getLabel(),
                        'warehouse' => $this->warehouseLabel($unit->warehouse, $unit->warehouse_id),
                    ]);
                })->all()),
                action: $this->correctLinesAction($operation),
            );
        }

        return $findings;
    }

    private function serialIsSaleableAt(InventoryOperationLine $line, SerializedInventoryUnit $unit, ?int $warehouseId): bool
    {
        return $unit->product_variant_id === $line->product_variant_id
            && $unit->warehouse_id === $warehouseId
            && $unit->status === SerializedInventoryUnitStatus::Available
            && $unit->stock_condition === StockCondition::Saleable
            && ($line->inventory_lot_id === null || $unit->inventory_lot_id === $line->inventory_lot_id);
    }

    private function correctLinesAction(InventoryOperation $operation): InventoryDiagnosticAction
    {
        if (! $operation->isDraft()) {
            return $this->guidance('operation_recreate');
        }

        return InventoryDiagnosticAction::link(
            $this->actionLabel('operation_edit_lines'),
            InventoryOperationResource::getUrl('edit', ['record' => $operation]),
            InventoryOperationResource::canEdit($operation),
        );
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function readyFindings(InventoryOperation $operation): array
    {
        if ($operation->operation_type === OperationType::Receipt) {
            return [$this->finding('operation.receipt_ready', Category::Receipt, Severity::Notice,
                [
                    'count' => $this->lines($operation)->count(),
                    'warehouse' => $this->warehouseLabel($operation->destinationWarehouse, $operation->destination_warehouse_id),
                ],
                facts: $this->lineFacts($operation, $this->lines($operation)),
                action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.complete_receipt', $this->can('complete', $operation)),
            )];
        }

        $findings = [];
        $missing = $this->linesWithoutActiveReservation($operation);

        if ($missing !== []) {
            $findings[] = $this->finding('operation.reservation_missing', Category::Reservation, Severity::Blocker,
                ['count' => count($missing)],
                facts: array_map(fn (array $entry): string => $entry['reservation'] instanceof InventoryReservation
                    ? $this->fact('op_line_reservation_status', [
                        'sku' => $this->sku($entry['line']),
                        'line' => $entry['position'],
                        'reservation' => '#'.$entry['reservation']->id,
                        'status' => $entry['reservation']->status->getLabel(),
                    ])
                    : $this->fact('op_line_no_reservation', [
                        'sku' => $this->sku($entry['line']),
                        'line' => $entry['position'],
                    ]), $missing),
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        $overdue = $operation->reservations
            ->filter(static fn (InventoryReservation $reservation): bool => $reservation->isActive()
                && $reservation->expires_at !== null
                && $reservation->expires_at->lessThanOrEqualTo(now()));

        if ($overdue->isNotEmpty()) {
            /** @var InventoryReservation $first */
            $first = $overdue->first();
            $findings[] = $this->finding('operation.reservation_overdue', Category::Reservation, Severity::Warning,
                ['count' => $overdue->count()],
                facts: array_values($overdue->map(fn (InventoryReservation $reservation): string => $this->fact('reservation_expired_at', [
                    'reservation' => '#'.$reservation->id,
                    'date' => $reservation->expires_at?->toDateTimeString() ?? '—',
                    'quantity' => $this->quantity($reservation->base_quantity),
                ]))->all()),
                action: $this->viewLink(InventoryReservationResource::class, $first, 'review_reservation'),
            );
        }

        if ($operation->operation_type === OperationType::InternalTransfer) {
            if ($missing === []) {
                $findings[] = $this->finding('operation.transfer_ready', Category::Transfer, Severity::Notice,
                    ['warehouse' => $this->warehouseLabel($operation->destinationWarehouse, $operation->destination_warehouse_id)],
                    action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.dispatch_transfer', $this->can('dispatch', $operation)),
                );
            }

            return $findings;
        }

        $shipment = $operation->shipment;

        if (! $shipment instanceof Shipment || $shipment->status !== ShipmentStatus::Planned) {
            $findings[] = $this->finding('operation.no_planned_shipment', Category::Delivery, Severity::Blocker,
                facts: [$shipment instanceof Shipment
                    ? $this->fact('op_shipment_status', ['tracking' => (string) $shipment->tracking_number, 'status' => $shipment->status->label()])
                    : $this->fact('op_no_shipment')],
                action: $this->fulfillmentAction($operation),
            );
        } elseif ($missing === []) {
            $findings[] = $this->finding('operation.delivery_ready', Category::Delivery, Severity::Notice,
                ['tracking' => (string) $shipment->tracking_number],
                action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.dispatch_delivery', $this->can('complete', $operation)),
            );
        }

        return $findings;
    }

    private function fulfillmentAction(InventoryOperation $operation): InventoryDiagnosticAction
    {
        if ($operation->source_document_type !== Order::class || ! is_numeric($operation->source_document_id)) {
            return $this->guidance('operation_no_shipment_path');
        }

        $order = Order::query()->find((int) $operation->source_document_id);

        if (! $order instanceof Order || $order->status !== OrderStatus::Released) {
            return $this->guidance('operation_no_shipment_path');
        }

        return $this->viewLink(OutboundFulfillmentResource::class, $order, 'operation_open_fulfillment', ['order' => (string) $order->order_number]);
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function transitFindings(InventoryOperation $operation): array
    {
        $lines = $this->lines($operation);
        $findings = [];

        $overReceived = $lines->filter(static fn (InventoryOperationLine $line): bool => (float) $line->received_base_quantity > (float) $line->dispatched_base_quantity + self::Epsilon);

        if ($overReceived->isNotEmpty()) {
            $findings[] = $this->finding('operation.transfer_over_received', Category::DataQuality, Severity::Blocker,
                facts: $this->transferLineFacts($overReceived),
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        $open = $lines->filter(static fn (InventoryOperationLine $line): bool => ! $line->discrepancy_disposition instanceof TransferDiscrepancyDisposition
            && (float) $line->dispatched_base_quantity - (float) $line->received_base_quantity > self::Epsilon);
        $remaining = (float) $open->sum(static fn (InventoryOperationLine $line): float => (float) $line->dispatched_base_quantity - (float) $line->received_base_quantity);
        $days = $operation->dispatched_at !== null ? $this->daysSince($operation->dispatched_at) : null;

        if ($open->isNotEmpty()) {
            $findings[] = $this->finding(
                $operation->isPartiallyReceived() ? 'operation.transfer_partially_received' : 'operation.transfer_in_transit',
                Category::Transfer,
                $operation->isPartiallyReceived() ? Severity::Warning : Severity::Notice,
                [
                    'count' => $open->count(),
                    'quantity' => $this->quantity($remaining, $this->commonUnit($open)),
                    'warehouse' => $this->warehouseLabel($operation->destinationWarehouse, $operation->destination_warehouse_id),
                    'days' => $days ?? '—',
                ],
                facts: [
                    ...($operation->dispatched_at !== null ? [$this->fact('op_dispatched_at', ['date' => $operation->dispatched_at->toDateTimeString(), 'days' => (int) $days])] : []),
                    ...$this->transferLineFacts($open),
                ],
                action: $this->buttonStep('operation_use_button', 'admin.inventory.operation.actions.receive_transfer', $this->can('receiveTransfer', $operation)),
            );
        }

        $findings = [...$findings, ...$this->dispositionFindings($lines)];

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return list<InventoryDiagnosticFinding>
     */
    private function dispositionFindings(Collection $lines): array
    {
        $disposed = $lines->filter(static fn (InventoryOperationLine $line): bool => $line->discrepancy_disposition instanceof TransferDiscrepancyDisposition);

        if ($disposed->isEmpty()) {
            return [];
        }

        return [$this->finding('operation.transfer_discrepancy', Category::Transfer, Severity::Notice,
            ['count' => $disposed->count()],
            facts: array_values($disposed->map(function (InventoryOperationLine $line): string {
                /** @var TransferDiscrepancyDisposition $disposition */
                $disposition = $line->discrepancy_disposition;
                $reason = $line->getAttribute('discrepancy_reason');

                return $this->fact('op_line_disposition', [
                    'sku' => $this->sku($line),
                    'missing' => $this->quantity((float) $line->dispatched_base_quantity - (float) $line->received_base_quantity, $this->unitSymbol($line->productVariant)),
                    'disposition' => $disposition->getLabel(),
                    'reason' => is_string($reason) && $reason !== '' ? $reason : '—',
                ]);
            })->all()),
        )];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function doneFindings(InventoryOperation $operation): array
    {
        $movements = $operation->movements()->count();
        $findings = [];

        if ($movements === 0 && $this->lines($operation)->isNotEmpty()) {
            $findings[] = $this->finding('operation.done_without_movements', Category::DataQuality, Severity::Warning,
                ['count' => $this->lines($operation)->count()],
                action: $this->guidance('escalate_inconsistency'),
            );
        } else {
            $findings[] = $this->finding('operation.done', Category::Workflow, Severity::Notice,
                ['count' => $movements, 'date' => $operation->completed_at?->toDateTimeString() ?? '—'],
            );
        }

        if ($operation->operation_type === OperationType::InternalTransfer) {
            return [...$findings, ...$this->dispositionFindings($this->lines($operation))];
        }

        return $findings;
    }

    /**
     * Done and Canceled operations must not hold stock: completion consumes and cancellation
     * releases every active reservation, so one that is still active contradicts the stage.
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function strayReservationFindings(InventoryOperation $operation): array
    {
        if (! $operation->isTerminal() && ! $operation->isInTransit() && ! $operation->isPartiallyReceived()) {
            return [];
        }

        $active = $operation->reservations->filter(static fn (InventoryReservation $reservation): bool => $reservation->isActive());

        if ($active->isEmpty()) {
            return [];
        }

        /** @var InventoryReservation $first */
        $first = $active->first();

        return [$this->finding('operation.stray_reservations', Category::Reservation, Severity::Blocker,
            ['count' => $active->count(), 'stage' => $operation->stageLabel()],
            facts: array_values($active->map(fn (InventoryReservation $reservation): string => $this->fact('op_active_reservation', [
                'reservation' => '#'.$reservation->id,
                'quantity' => $this->quantity($reservation->base_quantity),
            ]))->all()),
            action: $this->viewLink(InventoryReservationResource::class, $first, 'review_reservation'),
        )];
    }

    /**
     * Lines of an outbound operation that completion would post a reserved delta for without an
     * active reservation behind it.
     *
     * @return list<array{line: InventoryOperationLine, position: int, reservation: InventoryReservation|null}>
     */
    private function linesWithoutActiveReservation(InventoryOperation $operation): array
    {
        $byLine = $operation->reservations->groupBy('source_line_id');
        $missing = [];

        foreach ($this->lines($operation)->values() as $index => $line) {
            /** @var Collection<int, InventoryReservation> $reservations */
            $reservations = $byLine->get($line->id, new Collection);

            if ($reservations->contains(static fn (InventoryReservation $reservation): bool => $reservation->isActive())) {
                continue;
            }

            $missing[] = ['line' => $line, 'position' => $index + 1, 'reservation' => $reservations->last()];
        }

        return $missing;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $affected
     * @return list<string>
     */
    private function lineFacts(InventoryOperation $operation, Collection $affected): array
    {
        $positions = $this->lines($operation)->values()->mapWithKeys(static fn (InventoryOperationLine $line, int $index): array => [$line->id => $index + 1]);
        $facts = [];

        foreach ($affected->take(self::QuotedLines) as $line) {
            $facts[] = $this->fact('op_line_quantity', [
                'line' => (int) $positions->get($line->id, 0),
                'sku' => $this->sku($line),
                'quantity' => $this->quantity($this->lineQuantity($line), $this->unitSymbol($line->productVariant)),
            ]);
        }

        if ($affected->count() > self::QuotedLines) {
            $facts[] = $this->fact('op_more_lines', ['count' => $affected->count() - self::QuotedLines]);
        }

        return $facts;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return list<string>
     */
    private function transferLineFacts(Collection $lines): array
    {
        return array_values($lines->take(self::QuotedLines)->map(function (InventoryOperationLine $line): string {
            $unit = $this->unitSymbol($line->productVariant);
            $dispatched = (float) $line->dispatched_base_quantity;
            $received = (float) $line->received_base_quantity;

            return $this->fact('op_transfer_line', [
                'sku' => $this->sku($line),
                'dispatched' => $this->quantity($dispatched, $unit),
                'received' => $this->quantity($received, $unit),
                'remaining' => $this->quantity(max(0.0, $dispatched - $received), $unit),
            ]);
        })->all());
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return array{value: string, helper?: string|null}
     */
    private function demandFigure(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return ['value' => '—'];
        }

        $unit = $this->commonUnit($lines);
        $total = (float) $lines->sum(fn (InventoryOperationLine $line): float => $this->lineQuantity($line));

        return $unit === null && $lines->map(fn (InventoryOperationLine $line): ?string => $this->unitSymbol($line->productVariant))->unique()->count() > 1
            ? ['value' => '—', 'helper' => __('inventory_view_ux.ui.metric_mixed_units')]
            : ['value' => $this->quantity($total, $unit)];
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function commonUnit(Collection $lines): ?string
    {
        $units = $lines->map(fn (InventoryOperationLine $line): ?string => $this->unitSymbol($line->productVariant))->unique()->values();

        return $units->count() === 1 ? $units->first() : null;
    }

    private function buttonStep(string $labelKey, string $buttonKey, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($labelKey, ['button' => __($buttonKey)]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function can(string $ability, InventoryOperation $operation): bool
    {
        return auth()->user()?->can($ability, $operation) ?? false;
    }

    private function lineQuantity(InventoryOperationLine $line): float
    {
        return (float) ($line->base_quantity ?? $line->quantity);
    }

    private function percent(int $part, int $total): string
    {
        return $total > 0 ? (string) round($part / $total * 100).'%' : '—';
    }

    private function daysSince(\DateTimeInterface $moment): int
    {
        return max(0, (int) floor(now()->diffInDays($moment, true)));
    }

    private function warehouseLabel(?Warehouse $warehouse, ?int $warehouseId): string
    {
        if ($warehouse instanceof Warehouse) {
            return $warehouse->code.' — '.$warehouse->name;
        }

        return $warehouseId !== null ? '#'.$warehouseId : '—';
    }

    private function sku(InventoryOperationLine $line): string
    {
        return $line->productVariant->sku;
    }

    private function unitSymbol(?ProductVariant $variant): ?string
    {
        $unit = $variant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }

    /** @return Collection<int, InventoryOperationLine> */
    private function lines(InventoryOperation $operation): Collection
    {
        return $operation->lines->sortBy('id')->values();
    }

    private function load(InventoryOperation $operation): void
    {
        $operation->loadMissing([
            'lines.productVariant.unit',
            'lines.productVariant.product',
            'lines.lot',
            'lines.serializedUnit.warehouse',
            'sourceWarehouse',
            'destinationWarehouse',
            'reservations',
            'shipment',
        ]);
    }
}
