<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\StockCondition;
use App\Enums\SupportPermission;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventorySetting;
use App\Models\LotQualityAlert;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\Support\TicketProductContextService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains a lot's expiry risk and where its stock is restricted, using the configured
 * expiry windows ({@see InventorySetting::expiryWindows()}) and the per-warehouse condition
 * balances. FEFO and expired-stock issue rules are enforced by InventoryLotService; this
 * class only tells the user what those rules will do to this lot.
 */
final readonly class InventoryLotDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryLot;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryLot);

        $record->loadMissing(['conditionBalances.warehouse:id,code,name', 'productVariant.unit', 'canonicalLot']);
        $balances = $record->conditionBalances;
        $unit = $this->unitSymbol($record);
        $saleable = $this->sum($balances, StockCondition::Saleable, 'on_hand_base_quantity');
        $reserved = $this->sum($balances, StockCondition::Saleable, 'reserved_base_quantity');
        $quarantine = $this->sum($balances, StockCondition::Quarantine, 'on_hand_base_quantity');
        $damaged = $this->sum($balances, StockCondition::Damaged, 'on_hand_base_quantity');
        $physical = $saleable + $quarantine + $damaged;

        $findings = [];

        if ($record->canonicalLot instanceof InventoryLot) {
            $findings[] = $this->finding('lot.merged', Category::DataQuality, Severity::Notice,
                ['lot' => (string) $record->canonicalLot->lot_number],
                action: $this->viewLink(InventoryLotResource::class, $record->canonicalLot, 'open_canonical_lot'),
            );
        }

        $inconsistent = $balances->filter(static fn (InventoryLotBalance $balance): bool => (float) $balance->on_hand_base_quantity < 0
            || (float) $balance->reserved_base_quantity > (float) $balance->on_hand_base_quantity + 0.000001);

        if ($inconsistent->isNotEmpty()) {
            $findings[] = $this->finding('lot.inconsistent', Category::DataQuality, Severity::Blocker,
                facts: $inconsistent->map(fn (InventoryLotBalance $balance): string => $this->fact('lot_balance', [
                    'warehouse' => $this->warehouseLabel($balance),
                    'condition' => $balance->stock_condition->getLabel(),
                    'on_hand' => $this->quantity($balance->on_hand_base_quantity, $unit),
                    'reserved' => $this->quantity($balance->reserved_base_quantity, $unit),
                ]))->values()->all(),
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        $findings = [...$findings, ...$this->expiryFindings($record, $balances, $saleable, $unit)];

        if ($physical <= 0) {
            $findings[] = $this->finding('lot.no_stock', Category::Availability, Severity::Notice);
        }

        if ($reserved > 0) {
            $findings[] = $this->finding('lot.reserved', Category::Reservation, Severity::Notice,
                ['quantity' => $this->quantity($reserved, $unit)],
                facts: [$this->fact('available', ['quantity' => $this->quantity(max(0.0, $saleable - $reserved), $unit)])],
            );
        }

        if ($quarantine > 0) {
            $findings[] = $this->finding('lot.quarantine', Category::Condition, Severity::Warning,
                ['quantity' => $this->quantity($quarantine, $unit)],
                facts: $this->conditionFacts($balances, StockCondition::Quarantine, $unit),
                action: $this->conditionChangeLink($record, $balances, StockCondition::Quarantine, InventoryConditionChangeType::QuarantineDisposition, 'decide_quarantine'),
            );
        }

        if ($damaged > 0) {
            $findings[] = $this->finding('lot.damaged', Category::Condition, Severity::Warning,
                ['quantity' => $this->quantity($damaged, $unit)],
                facts: $this->conditionFacts($balances, StockCondition::Damaged, $unit),
                action: $this->conditionChangeLink($record, $balances, StockCondition::Damaged, InventoryConditionChangeType::DamageRecovery, 'resolve_damage'),
            );
        }

        $qualityFinding = $this->qualityFinding($record);

        if ($qualityFinding instanceof InventoryDiagnosticFinding) {
            $findings[] = $qualityFinding;
        }

        $windows = InventorySetting::expiryWindows();

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('lot_expiry', $windows),
                $this->check('lot_expiry_required'),
                $this->check('lot_balance_consistency'),
                $this->check('lot_conditions'),
                $this->check('lot_reservations'),
                $this->check('lot_quality'),
            ],
            limits: [
                $this->limit('recorded_balances'),
                $this->limit('lot_quality_decision'),
            ],
        );
    }

    /**
     * @param  Collection<int, InventoryLotBalance>  $balances
     * @return list<InventoryDiagnosticFinding>
     */
    private function expiryFindings(InventoryLot $lot, Collection $balances, float $saleable, ?string $unit): array
    {
        $variant = $lot->productVariant;

        if ($lot->expires_at === null) {
            return $variant instanceof ProductVariant && $variant->tracksExpirationConfigured()
                ? [$this->finding('lot.expiry_missing', Category::DataQuality, Severity::Warning, action: $this->guidance('record_expiry'))]
                : [];
        }

        $days = (int) $lot->daysRemaining();
        $bucket = $lot->expiryBucket();
        $replace = [
            'date' => $lot->expires_at->toDateString(),
            'days' => abs($days),
            'quantity' => $this->quantity($saleable, $unit),
        ];
        $facts = $this->conditionFacts($balances, StockCondition::Saleable, $unit);

        if ($bucket === 'expired') {
            return [$saleable > 0
                ? $this->finding('lot.expired_with_stock', Category::Expiry, Severity::Blocker, $replace, $facts,
                    $this->conditionChangeLink($lot, $balances, StockCondition::Saleable, InventoryConditionChangeType::Damage, 'segregate_expired'))
                : $this->finding('lot.expired_empty', Category::Expiry, Severity::Notice, $replace)];
        }

        if ($saleable <= 0 || ! in_array($bucket, ['critical', 'warning', 'notice'], true)) {
            return [];
        }

        return [$this->finding('lot.expiring_'.$bucket, Category::Expiry,
            $bucket === 'critical' ? Severity::Warning : Severity::Notice,
            $replace,
            $facts,
            $this->indexLink(InventoryLotResource::class, 'review_expiring_lots'),
        )];
    }

    private function qualityFinding(InventoryLot $lot): ?InventoryDiagnosticFinding
    {
        if (! TicketProductContextService::enabled() || ! (auth()->user()?->can(SupportPermission::QualityComplaintView->value) ?? false)) {
            return null;
        }

        $alert = LotQualityAlert::query()
            ->where('inventory_lot_id', $lot->canonical_inventory_lot_id ?? $lot->getKey())
            ->whereNull('acknowledged_at')
            ->first();

        if (! $alert instanceof LotQualityAlert) {
            return null;
        }

        return $this->finding('lot.quality_alert', Category::Condition, Severity::Warning,
            ['count' => (int) $alert->open_complaints, 'threshold' => (int) $alert->threshold],
            action: $this->guidance('decide_lot_quality'),
        );
    }

    /**
     * @param  Collection<int, InventoryLotBalance>  $balances
     */
    private function conditionChangeLink(
        InventoryLot $lot,
        Collection $balances,
        StockCondition $condition,
        InventoryConditionChangeType $type,
        string $labelKey,
    ): ?InventoryDiagnosticAction {
        $balance = $balances->first(static fn (InventoryLotBalance $balance): bool => $balance->stock_condition === $condition
            && (float) $balance->on_hand_base_quantity > 0);

        if (! $balance instanceof InventoryLotBalance) {
            return null;
        }

        return $this->createLink(InventoryConditionChangeResource::class, $labelKey, [
            'type' => $type->value,
            'product_variant_id' => $lot->product_variant_id,
            'warehouse_id' => $balance->warehouse_id,
        ]);
    }

    /**
     * @param  Collection<int, InventoryLotBalance>  $balances
     * @return list<string>
     */
    private function conditionFacts(Collection $balances, StockCondition $condition, ?string $unit): array
    {
        return $balances
            ->filter(static fn (InventoryLotBalance $balance): bool => $balance->stock_condition === $condition
                && (float) $balance->on_hand_base_quantity > 0)
            ->map(fn (InventoryLotBalance $balance): string => $this->fact('in_warehouse', [
                'warehouse' => $this->warehouseLabel($balance),
                'quantity' => $this->quantity($balance->on_hand_base_quantity, $unit),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, InventoryLotBalance>  $balances
     */
    private function sum(Collection $balances, StockCondition $condition, string $column): float
    {
        return (float) $balances
            ->filter(static fn (InventoryLotBalance $balance): bool => $balance->stock_condition === $condition)
            ->sum(static fn (InventoryLotBalance $balance): float => (float) $balance->getAttribute($column));
    }

    private function warehouseLabel(InventoryLotBalance $balance): string
    {
        $warehouse = $balance->warehouse;

        return $warehouse !== null ? $warehouse->code.' — '.$warehouse->name : '#'.$balance->warehouse_id;
    }

    private function unitSymbol(InventoryLot $lot): ?string
    {
        $unit = $lot->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
