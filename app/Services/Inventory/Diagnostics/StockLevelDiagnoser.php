<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ReservationStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\StockLevels\Tables\StockLevelsTable;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\WarehouseReplenishmentPolicies\WarehouseReplenishmentPolicyResource;
use App\Models\InventoryAlert;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\Inventory\StockAvailabilityExplainer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Explains one SKU-in-warehouse balance: why on-hand stock is not available, whether the
 * replenishment threshold is breached, and which documents hold or will change the stock.
 * Builds on {@see StockAvailabilityExplainer}, whose causes sum exactly to the on-hand/available gap.
 */
final readonly class StockLevelDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    public function __construct(private StockAvailabilityExplainer $explainer) {}

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryStock;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryStock);

        $explanation = $this->explainer->explainStock($record);
        $unit = $this->unitSymbol($record);
        $onHand = $explanation['on_hand'];
        $available = $explanation['available'];
        $reserved = $record->conditionReservedQuantity(StockCondition::Saleable);
        $saleable = $record->conditionOnHandQuantity(StockCondition::Saleable);

        $findings = [];

        if ($onHand < 0 || $reserved > $saleable + 0.000001) {
            $findings[] = $this->finding('stock.inconsistent', Category::DataQuality, Severity::Blocker,
                facts: [
                    $this->fact('on_hand', ['quantity' => $this->quantity($onHand, $unit)]),
                    $this->fact('saleable_on_hand', ['quantity' => $this->quantity($saleable, $unit)]),
                    $this->fact('reserved', ['quantity' => $this->quantity($reserved, $unit)]),
                ],
                action: $this->movementsLink($record),
            );
        }

        if ($onHand <= 0) {
            $findings[] = $this->finding('stock.no_stock', Category::Availability, Severity::Warning,
                facts: [$this->fact('on_hand', ['quantity' => $this->quantity($onHand, $unit)])],
                action: $this->movementsLink($record),
            );
        } elseif ($available <= 0) {
            $findings[] = $this->finding('stock.none_available', Category::Availability, Severity::Warning,
                facts: array_map(
                    fn (array $cause): string => $cause['label'].': '.$this->quantity($cause['quantity'], $unit),
                    $explanation['causes'],
                ),
            );
        }

        foreach ($explanation['causes'] as $cause) {
            $findings[] = $this->causeFinding($record, $cause, $unit);
        }

        $findings = [...$findings, ...$this->expiredLotFindings($explanation['expired'], $unit)];
        $findings = [...$findings, ...$this->reservationFindings($record)];
        $findings = [...$findings, ...$this->replenishmentFindings($record, $available, $explanation['in_transit']['quantity'], $unit)];
        $findings = [...$findings, ...$this->waitingDeliveryFindings($record, $available, $unit)];
        $findings = [...$findings, ...$this->alertFindings($record)];

        if ($explanation['in_transit']['quantity'] > 0) {
            $first = $explanation['in_transit']['operations'][0] ?? null;
            $findings[] = $this->finding('stock.in_transit', Category::Transfer, Severity::Notice,
                ['quantity' => $this->quantity($explanation['in_transit']['quantity'], $unit)],
                facts: array_map(
                    fn (array $operation): string => $operation['label'].': '.$this->quantity($operation['quantity'], $unit),
                    $explanation['in_transit']['operations'],
                ),
                action: $first !== null && $first['url'] !== null
                    ? $this->link('open_transfer', $first['url'], InventoryOperationResource::canViewAny())
                    : null,
            );
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('balance_consistency'),
                $this->check('availability_causes'),
                $this->check('expired_lots'),
                $this->check('reservation_expiry'),
                $this->check('replenishment_threshold'),
                $this->check('waiting_deliveries'),
                $this->check('active_alerts'),
                $this->check('inbound_transfers'),
            ],
            limits: [
                $this->limit('recorded_balances'),
                $this->limit('unposted_documents'),
            ],
        );
    }

    /**
     * @param  array{cause: string, label: string, quantity: float, documents: list<array{type: string, label: string, url: string|null, quantity: float|null, expires_at: string|null, holder: string|null}>, action: array{label: string, url: string}|null}  $cause
     */
    private function causeFinding(InventoryStock $stock, array $cause, ?string $unit): InventoryDiagnosticFinding
    {
        $facts = array_map(
            fn (array $document): string => trim($document['label']
                .($document['holder'] !== null && $document['holder'] !== '' ? ' — '.$document['holder'] : '')
                .($document['quantity'] !== null ? ': '.$this->quantity($document['quantity'], $unit) : '')),
            $cause['documents'],
        );

        if ($facts === []) {
            $facts[] = $this->fact('no_source_document');
        }

        $replace = ['quantity' => $this->quantity($cause['quantity'], $unit), 'count' => count($cause['documents'])];

        return match ($cause['cause']) {
            'reserved' => $this->finding('stock.reserved', Category::Reservation, Severity::Notice, $replace, $facts,
                $this->indexLink(InventoryReservationResource::class, 'review_reservations', $this->filtered([
                    'warehouse_id' => $stock->warehouse_id,
                    'product_variant_id' => $stock->product_variant_id,
                    'status' => ReservationStatus::Active->value,
                ])),
            ),
            'quarantine' => $this->finding('stock.quarantine', Category::Condition, Severity::Warning, $replace, $facts,
                $cause['action'] !== null
                    ? $this->link('decide_quarantine', $cause['action']['url'], InventoryConditionChangeResource::canCreate())
                    : $this->guidance('complete_open_disposition'),
            ),
            default => $this->finding('stock.damaged', Category::Condition, Severity::Warning, $replace, $facts,
                $cause['action'] !== null
                    ? $this->link('resolve_damage', $cause['action']['url'], InventoryConditionChangeResource::canCreate())
                    : null,
            ),
        };
    }

    /**
     * @param  array{quantity: float, lots: list<array{label: string, url: string|null, quantity: float, expires_at: string|null}>}  $expired
     * @return list<InventoryDiagnosticFinding>
     */
    private function expiredLotFindings(array $expired, ?string $unit): array
    {
        if ($expired['quantity'] <= 0) {
            return [];
        }

        $first = $expired['lots'][0] ?? null;

        return [$this->finding('stock.expired_lots', Category::Expiry, Severity::Warning,
            ['quantity' => $this->quantity($expired['quantity'], $unit)],
            facts: array_map(
                fn (array $lot): string => $this->fact('lot_expired_on', [
                    'lot' => $lot['label'],
                    'date' => $lot['expires_at'] ?? '—',
                    'quantity' => $this->quantity($lot['quantity'], $unit),
                ]),
                $expired['lots'],
            ),
            action: $first !== null && $first['url'] !== null
                ? $this->link('open_lot', $first['url'], InventoryLotResource::canViewAny())
                : null,
        )];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function reservationFindings(InventoryStock $stock): array
    {
        $overdue = InventoryReservation::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('status', ReservationStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->get();

        if ($overdue->isEmpty()) {
            return [];
        }

        /** @var InventoryReservation $first */
        $first = $overdue->first();

        return [$this->finding('stock.reservation_overdue', Category::Reservation, Severity::Warning,
            ['count' => $overdue->count()],
            facts: $overdue->map(fn (InventoryReservation $reservation): string => $this->fact('reservation_expired_at', [
                'reservation' => '#'.$reservation->getKey(),
                'date' => $reservation->expires_at?->toDateTimeString() ?? '—',
                'quantity' => $this->quantity($reservation->base_quantity),
            ]))->values()->all(),
            action: $this->viewLink(InventoryReservationResource::class, $first, 'review_reservation'),
        )];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function replenishmentFindings(InventoryStock $stock, float $available, float $inTransit, ?string $unit): array
    {
        $policy = $stock->replenishmentPolicy();
        $policyLink = $this->indexLink(WarehouseReplenishmentPolicyResource::class, 'review_policy', $this->filtered([
            'warehouse_id' => $stock->warehouse_id,
        ]));

        if ($policy === null) {
            return [$this->finding('stock.no_policy', Category::Replenishment, Severity::Notice, action: $policyLink)];
        }

        if (! $policy->is_active) {
            return [$this->finding('stock.policy_inactive', Category::Replenishment, Severity::Notice, action: $policyLink)];
        }

        if (! $policy->isBreachedBy($stock)) {
            return [];
        }

        $minimum = (float) $policy->min_quantity;
        $target = is_numeric($policy->max_quantity) ? (float) $policy->max_quantity : $minimum;
        $shortage = max(0.0, $target - $available);
        $facts = [
            $this->fact('available', ['quantity' => $this->quantity($available, $unit)]),
            $this->fact('minimum', ['quantity' => $this->quantity($minimum, $unit)]),
            $this->fact('target', ['quantity' => $this->quantity($target, $unit)]),
        ];

        if ($inTransit > 0) {
            $facts[] = $this->fact('in_transit', ['quantity' => $this->quantity($inTransit, $unit)]);
        }

        $covered = $inTransit > 0 && $available + $inTransit > $minimum;

        return [$this->finding(
            $covered ? 'stock.below_minimum_covered' : 'stock.below_minimum',
            Category::Replenishment,
            $covered ? Severity::Notice : Severity::Warning,
            ['shortage' => $this->quantity($shortage, $unit)],
            $facts,
            $policyLink,
        )];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function waitingDeliveryFindings(InventoryStock $stock, float $available, ?string $unit): array
    {
        $lines = InventoryOperationLine::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::Delivery->value)
                ->where('source_warehouse_id', $stock->warehouse_id)
                ->where('stage', OperationStage::Waiting->value))
            ->with('operation')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $demand = (float) $lines->sum(fn (InventoryOperationLine $line): float => (float) ($line->base_quantity ?? $line->quantity));
        $operations = $lines->groupBy('inventory_operation_id');
        /** @var InventoryOperation $first */
        $first = $lines->first()?->operation;

        return [$this->finding('stock.waiting_deliveries', Category::Delivery,
            $demand > $available ? Severity::Warning : Severity::Notice,
            ['count' => $operations->count()],
            facts: [
                $this->fact('waiting_demand', ['quantity' => $this->quantity($demand, $unit)]),
                $this->fact('available', ['quantity' => $this->quantity($available, $unit)]),
            ],
            action: $this->viewLink(InventoryOperationResource::class, $first, 'open_delivery', [
                'number' => (string) ($first->operation_number ?? '#'.$first->getKey()),
            ]),
        )];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function alertFindings(InventoryStock $stock): array
    {
        $alerts = InventoryAlert::query()
            ->whereNull('resolved_at')
            ->where('subject_type', InventoryStock::class)
            ->where('subject_id', $stock->getKey())
            ->latest('id')
            ->get();

        if ($alerts->isEmpty()) {
            return [];
        }

        /** @var InventoryAlert $first */
        $first = $alerts->first();

        return [$this->finding('stock.active_alerts', Category::DataQuality, Severity::Warning,
            ['count' => $alerts->count()],
            facts: $alerts->map(static fn (InventoryAlert $alert): string => (string) $alert->message)->values()->all(),
            action: $this->viewLink(InventoryAlertResource::class, $first, 'open_alert'),
        )];
    }

    private function movementsLink(InventoryStock $stock): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel('review_movements'),
            StockLevelsTable::packageMovementsUrl($stock),
            StockMovementResource::canViewAny(),
        );
    }

    private function unitSymbol(InventoryStock $stock): ?string
    {
        $variant = $stock->productVariant;
        $unit = $variant instanceof ProductVariant ? $variant->unit : null;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
