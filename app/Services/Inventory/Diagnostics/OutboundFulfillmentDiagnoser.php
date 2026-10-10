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
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SalesProcurementRequirement;
use App\Models\Shipment;
use App\Models\Unit;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Sales\OrderWorkflowProjectionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains why a released sales order is (not) moving through outbound fulfillment: missing
 * delivery plan, open supply requirements, deliveries waiting for stock, ready deliveries that
 * cannot be dispatched, and shipments still in transit.
 *
 * Quantities come from {@see OrderWorkflowProjectionStore} (the numbers the page shows) and the
 * stock check uses the same Σ available_quantity per warehouse/SKU that
 * {@see OutboundFulfillmentService} validates a plan against. Every next step is offered only
 * when the current user passes the same policy ability the header action enforces.
 */
final readonly class OutboundFulfillmentDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Tolerance = 0.000001;

    /** @var list<string> */
    private const array ClosedRequirementStatuses = ['fulfilled', 'cancelled', 'superseded'];

    public function __construct(private OrderWorkflowProjectionStore $projections) {}

    public function supports(Model $record): bool
    {
        return $record instanceof Order;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof Order);

        $record->loadMissing([
            'deliveries.sourceWarehouse:id,code,name',
            'deliveries.shipment',
            'deliveries.lines.productVariant.unit',
            'procurementRequirements.productVariant.unit',
            'shipments.delivery',
        ]);

        $deliveries = $record->deliveries->filter(static fn (InventoryOperation $delivery): bool => $delivery->stage !== OperationStage::Canceled);

        $findings = [
            ...$this->supplyFindings($record),
            ...$this->unlinkedDeliveryFindings($record, $deliveries),
            ...$this->planFindings($record, $deliveries),
            ...$this->waitingFindings($deliveries),
            ...$this->draftFindings($deliveries),
            ...$this->readyFindings($deliveries),
            ...$this->inTransitFindings($record),
        ];

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('outbound_supply'),
                $this->check('outbound_plan'),
                $this->check('outbound_waiting_stock'),
                $this->check('outbound_draft_deliveries'),
                $this->check('outbound_ready_shipment'),
                $this->check('outbound_in_transit'),
            ],
            limits: [
                $this->limit('recorded_balances'),
                $this->limit('outbound_customer_arrival'),
            ],
        );
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function supplyFindings(Order $order): array
    {
        $open = $order->procurementRequirements->filter(static fn (SalesProcurementRequirement $requirement): bool => ! in_array((string) $requirement->getAttribute('status'), self::ClosedRequirementStatuses, true)
            && (float) $requirement->outstandingBaseQuantity() > self::Tolerance);

        if ($open->isEmpty()) {
            return [];
        }

        return [$this->finding('outbound.supply_blocked', Category::Replenishment, Severity::Warning,
            ['count' => $open->count()],
            facts: $open->map(function (SalesProcurementRequirement $requirement): string {
                $variant = $requirement->productVariant;
                $unit = $this->unitSymbol($variant);

                return $this->fact('outbound_supply_requirement', [
                    'sku' => $variant instanceof ProductVariant ? $variant->sku : '—',
                    'required' => $this->quantity($requirement->required_base_quantity, $unit),
                    'received' => $this->quantity($requirement->fulfilled_base_quantity, $unit),
                    'outstanding' => $this->quantity($requirement->outstandingBaseQuantity(), $unit),
                ]);
            })->values()->all(),
            action: InventoryDiagnosticAction::link($this->actionLabel('outbound_review_supply'), PurchaseNeeds::getUrl(), PurchaseNeeds::canAccess()),
        )];
    }

    /**
     * A Ready delivery with no linked order lines must never be used to imply
     * that all Sales demand was allocated. Surface the inconsistent linkage.
     *
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<InventoryDiagnosticFinding>
     */
    private function unlinkedDeliveryFindings(Order $order, Collection $deliveries): array
    {
        $orderLineIds = $order->lines()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $unlinked = $deliveries->filter(static fn (InventoryOperation $delivery): bool => $delivery->lines->isEmpty()
            || $delivery->lines->contains(static fn (InventoryOperationLine $line): bool => $line->order_line_id === null
                || ! in_array((int) $line->order_line_id, $orderLineIds, true)))->values();

        $first = $unlinked->first();

        if (! $first instanceof InventoryOperation) {
            return [];
        }

        return [$this->finding(
            'outbound.unlinked_delivery',
            Category::DataQuality,
            Severity::Warning,
            ['count' => $unlinked->count()],
            facts: $unlinked->map(fn (InventoryOperation $delivery): string => $this->fact('outbound_delivery_at', [
                'delivery' => $this->deliveryLabel($delivery),
                'warehouse' => $this->warehouseLabel($delivery),
            ]))->all(),
            action: $this->viewLink(InventoryOperationResource::class, $first, 'outbound_open_delivery', [
                'number' => $this->deliveryLabel($first),
            ]),
        )];
    }

    /**
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<InventoryDiagnosticFinding>
     */
    private function planFindings(Order $order, Collection $deliveries): array
    {
        $projection = $this->projections->project($order);

        if (round($projection->remainingBase, 6) <= 0.0) {
            return [];
        }

        return [$this->finding(
            $deliveries->isEmpty() ? 'outbound.no_plan' : 'outbound.partially_planned',
            Category::Delivery,
            Severity::Notice,
            ['quantity' => $this->quantity($projection->remainingBase)],
            facts: [],
            action: $this->step(
                __('admin.inventory.outbound.actions.create_delivery_plan'),
                auth()->user()?->can('planFulfillment', $order) ?? false,
            ),
        )];
    }

    /**
     * A Waiting delivery could not reserve its stock. Compare each SKU's demand with the stock
     * available now at the delivery's source warehouse (one aggregate query for all of them).
     *
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<InventoryDiagnosticFinding>
     */
    private function waitingFindings(Collection $deliveries): array
    {
        $waiting = $deliveries->filter(static fn (InventoryOperation $delivery): bool => $delivery->stage === OperationStage::Waiting)->values();

        if ($waiting->isEmpty()) {
            return [];
        }

        $available = $this->availableByWarehouseAndVariant($waiting);
        $shortageFacts = [];
        $coveredFacts = [];
        $firstShort = null;
        $firstCovered = null;

        foreach ($waiting as $delivery) {
            $demandByVariant = [];

            foreach ($delivery->lines as $line) {
                assert($line instanceof InventoryOperationLine);
                $demandByVariant[$line->product_variant_id] ??= ['variant' => $line->productVariant, 'quantity' => 0.0];
                $demandByVariant[$line->product_variant_id]['quantity'] += (float) ($line->base_quantity ?? $line->quantity);
            }

            $short = false;

            foreach ($demandByVariant as $variantId => $demand) {
                $free = $available[$delivery->source_warehouse_id.':'.$variantId] ?? 0.0;

                if ($free + self::Tolerance >= $demand['quantity']) {
                    continue;
                }

                $short = true;
                $unit = $this->unitSymbol($demand['variant']);
                $shortageFacts[] = $this->fact('outbound_waiting_shortage', [
                    'delivery' => $this->deliveryLabel($delivery),
                    'sku' => $demand['variant'] instanceof ProductVariant ? $demand['variant']->sku : '#'.$variantId,
                    'needed' => $this->quantity($demand['quantity'], $unit),
                    'available' => $this->quantity($free, $unit),
                    'warehouse' => $this->warehouseLabel($delivery),
                ]);
            }

            if ($short) {
                $firstShort ??= $delivery;
            } else {
                $firstCovered ??= $delivery;
                $coveredFacts[] = $this->fact('outbound_waiting_covered', [
                    'delivery' => $this->deliveryLabel($delivery),
                    'warehouse' => $this->warehouseLabel($delivery),
                ]);
            }
        }

        $findings = [];

        if ($firstShort instanceof InventoryOperation) {
            $findings[] = $this->finding('outbound.delivery_waiting', Category::Availability, Severity::Warning,
                ['count' => count($shortageFacts)],
                $shortageFacts,
                $this->viewLink(InventoryOperationResource::class, $firstShort, 'outbound_open_delivery', [
                    'number' => $this->deliveryLabel($firstShort),
                ]),
            );
        }

        if ($firstCovered instanceof InventoryOperation) {
            $findings[] = $this->finding('outbound.waiting_now_covered', Category::Availability, Severity::Notice,
                ['count' => count($coveredFacts)],
                $coveredFacts,
                $this->step(
                    __('admin.inventory.outbound.actions.prepare_delivery'),
                    auth()->user()?->can('markReady', $firstCovered) ?? false,
                ),
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<InventoryDiagnosticFinding>
     */
    private function draftFindings(Collection $deliveries): array
    {
        $drafts = $deliveries->filter(static fn (InventoryOperation $delivery): bool => $delivery->stage === OperationStage::Draft)->values();
        $first = $drafts->first();

        if (! $first instanceof InventoryOperation) {
            return [];
        }

        return [$this->finding('outbound.draft_delivery', Category::Delivery, Severity::Notice,
            ['count' => $drafts->count()],
            facts: $drafts->map(fn (InventoryOperation $delivery): string => $this->fact('outbound_delivery_at', [
                'delivery' => $this->deliveryLabel($delivery),
                'warehouse' => $this->warehouseLabel($delivery),
            ]))->all(),
            action: $this->step(
                __('admin.inventory.outbound.actions.prepare_delivery'),
                auth()->user()?->can('markReady', $first) ?? false,
            ),
        )];
    }

    /**
     * Dispatch requires the delivery's own Planned shipment ({@see OutboundDispatchService}).
     *
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<InventoryDiagnosticFinding>
     */
    private function readyFindings(Collection $deliveries): array
    {
        $ready = $deliveries->filter(static fn (InventoryOperation $delivery): bool => $delivery->stage === OperationStage::Ready)->values();

        if ($ready->isEmpty()) {
            return [];
        }

        $blocked = $ready->filter(static fn (InventoryOperation $delivery): bool => ! $delivery->shipment instanceof Shipment
            || $delivery->shipment->status !== ShipmentStatus::Planned)->values();
        $dispatchable = $ready->diff($blocked)->values();
        $findings = [];

        if ($blocked->isNotEmpty()) {
            $findings[] = $this->finding('outbound.ready_without_shipment', Category::Delivery, Severity::Blocker,
                ['count' => $blocked->count()],
                facts: $blocked->map(fn (InventoryOperation $delivery): string => $this->fact('outbound_delivery_shipment', [
                    'delivery' => $this->deliveryLabel($delivery),
                    'status' => $delivery->shipment instanceof Shipment
                        ? $delivery->shipment->status->getLabel()
                        : $this->fact('outbound_no_shipment'),
                ]))->all(),
                action: $this->guidance('outbound_escalate_shipment'),
            );
        }

        $first = $dispatchable->first();

        if ($first instanceof InventoryOperation) {
            $findings[] = $this->finding('outbound.ready_to_dispatch', Category::Delivery, Severity::Notice,
                ['count' => $dispatchable->count()],
                facts: $dispatchable->map(fn (InventoryOperation $delivery): string => $this->fact('outbound_delivery_at', [
                    'delivery' => $this->deliveryLabel($delivery),
                    'warehouse' => $this->warehouseLabel($delivery),
                ]))->all(),
                action: $this->step(
                    __('admin.inventory.outbound.actions.dispatch_goods'),
                    auth()->user()?->can('complete', $first) ?? false,
                ),
            );
        }

        return $findings;
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function inTransitFindings(Order $order): array
    {
        $inTransit = $order->shipments->filter(static fn (Shipment $shipment): bool => $shipment->status === ShipmentStatus::InTransit)->values();
        $first = $inTransit->first();

        if (! $first instanceof Shipment) {
            return [];
        }

        return [$this->finding('outbound.in_transit', Category::Delivery, Severity::Notice,
            ['count' => $inTransit->count()],
            facts: $inTransit->map(function (Shipment $shipment): string {
                $delivery = $shipment->delivery;
                $dispatchedAt = $delivery instanceof InventoryOperation ? ($delivery->dispatched_at ?? $delivery->completed_at) : null;

                return $dispatchedAt === null
                    ? $this->fact('outbound_in_transit_undated', ['shipment' => (string) $shipment->tracking_number])
                    : $this->fact('outbound_in_transit_since', [
                        'shipment' => (string) $shipment->tracking_number,
                        'date' => $dispatchedAt->toDateString(),
                        'days' => (int) $dispatchedAt->copy()->startOfDay()->diffInDays(now()->startOfDay()),
                    ]);
            })->all(),
            action: $this->step(
                __('admin.inventory.outbound.actions.confirm_arrival'),
                auth()->user()?->can('confirm', $first) ?? false,
            ),
        )];
    }

    /**
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return array<string, float>
     */
    private function availableByWarehouseAndVariant(Collection $deliveries): array
    {
        $warehouseIds = $deliveries->pluck('source_warehouse_id')->filter()->unique()->values()->all();
        $variantIds = $deliveries->flatMap(static fn (InventoryOperation $delivery): Collection => $delivery->lines->pluck('product_variant_id'))
            ->unique()->values()->all();

        if ($warehouseIds === [] || $variantIds === []) {
            return [];
        }

        $available = [];

        InventoryStock::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_variant_id', $variantIds)
            ->selectRaw('warehouse_id, product_variant_id, SUM(available_quantity) as available_total')
            ->groupBy('warehouse_id', 'product_variant_id')
            ->toBase()
            ->get()
            ->each(static function (object $row) use (&$available): void {
                $available[$row->warehouse_id.':'.$row->product_variant_id] = (float) $row->available_total;
            });

        return $available;
    }

    /**
     * A step performed with a header button of this page, named exactly as the button shows it.
     */
    private function step(string $button, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel('outbound_use_button', ['button' => $button]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function deliveryLabel(InventoryOperation $delivery): string
    {
        return (string) ($delivery->operation_number ?: __('admin.inventory.outbound.placeholders.draft').' #'.$delivery->getKey());
    }

    private function warehouseLabel(InventoryOperation $delivery): string
    {
        $warehouse = $delivery->sourceWarehouse;

        return $warehouse !== null ? $warehouse->code.' — '.$warehouse->name : '#'.$delivery->source_warehouse_id;
    }

    private function unitSymbol(?ProductVariant $variant): ?string
    {
        $unit = $variant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
