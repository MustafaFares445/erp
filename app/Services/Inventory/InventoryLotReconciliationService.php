<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InventoryLotReconciliationService
{
    /**
     * Inspect canonical lot/condition/serial/reservation grains without modifying data.
     *
     * @return array{
     *   checked_lot_balances:int,
     *   checked_aggregate_balances:int,
     *   checked_reservation_grains:int,
     *   checked_serial_grains:int,
     *   checked_return_lines:int,
     *   errors:list<string>
     * }
     */
    public function inspect(): array
    {
        $errors = [];

        $missingTables = array_values(array_filter([
            'inventory_lots',
            'inventory_lot_balances',
            'inventory_condition_balances',
            'inventory_reservations',
            'inventory_reservation_allocations',
            'serialized_inventory_units',
            'inventory_returns',
            'inventory_return_lines',
        ], fn (string $table): bool => ! Schema::hasTable($table)));

        if ($missingTables !== []) {
            return [
                'checked_lot_balances' => 0,
                'checked_aggregate_balances' => 0,
                'checked_reservation_grains' => 0,
                'checked_serial_grains' => 0,
                'checked_return_lines' => 0,
                'errors' => [
                    'Canonical lot reconciliation cannot run because required migrations are incomplete. '
                    .'Missing table(s): '.implode(', ', $missingTables).'.',
                ],
            ];
        }

        $checkedLotBalances = $this->checkLotBalanceConstraints($errors);
        $checkedAggregateBalances = $this->checkAggregateReconciliation($errors);
        $checkedReservationGrains = $this->checkReservationReconciliation($errors);
        $checkedSerialGrains = $this->checkSerialReconciliation($errors);
        $checkedReturnLines = $this->checkReturnReconciliation($errors);
        $this->checkCanonicalIdentityReferences($errors);

        return [
            'checked_lot_balances' => $checkedLotBalances,
            'checked_aggregate_balances' => $checkedAggregateBalances,
            'checked_reservation_grains' => $checkedReservationGrains,
            'checked_serial_grains' => $checkedSerialGrains,
            'checked_return_lines' => $checkedReturnLines,
            'errors' => $errors,
        ];
    }

    /** @param list<string> &$errors */
    private function checkLotBalanceConstraints(array &$errors): int
    {
        $checked = 0;

        InventoryLotBalance::query()
            ->with('lot:id,product_variant_id,canonical_inventory_lot_id')
            ->orderBy('id')
            ->chunkById(200, function ($balances) use (&$errors, &$checked): void {
                foreach ($balances as $balance) {
                    $checked++;
                    $onHand = (string) $balance->on_hand_base_quantity;
                    $reserved = (string) $balance->reserved_base_quantity;

                    if (! $balance->lot instanceof InventoryLot) {
                        $errors[] = sprintf('Lot balance %d has no lot identity.', $balance->getKey());

                        continue;
                    }

                    if ($balance->lot->canonical_inventory_lot_id !== null) {
                        $errors[] = sprintf(
                            'Lot balance %d points to legacy alias lot %d.',
                            $balance->getKey(),
                            $balance->inventory_lot_id,
                        );
                    }

                    if (bccomp($onHand, '0', 6) < 0 || bccomp($reserved, '0', 6) < 0) {
                        $errors[] = sprintf('Lot balance %d contains a negative quantity.', $balance->getKey());
                    }

                    if (
                        $balance->stock_condition === StockCondition::Saleable
                        && bccomp($reserved, $onHand, 6) > 0
                    ) {
                        $errors[] = sprintf(
                            'Lot %d / warehouse %d reserves more than saleable on-hand.',
                            $balance->inventory_lot_id,
                            $balance->warehouse_id,
                        );
                    }

                    if (
                        $balance->stock_condition !== StockCondition::Saleable
                        && bccomp($reserved, '0', 6) !== 0
                    ) {
                        $errors[] = sprintf(
                            'Lot %d / warehouse %d has a reservation in %s condition.',
                            $balance->inventory_lot_id,
                            $balance->warehouse_id,
                            $balance->stock_condition->value,
                        );
                    }
                }
            });

        return $checked;
    }

    /** @param list<string> &$errors */
    private function checkAggregateReconciliation(array &$errors): int
    {
        $checked = 0;

        ProductVariant::query()
            ->where('track_batches', true)
            ->orderBy('id')
            ->chunkById(100, function ($variants) use (&$errors, &$checked): void {
                foreach ($variants as $variant) {
                    $aggregateGrains = InventoryConditionBalance::query()
                        ->where('product_variant_id', $variant->getKey())
                        ->orderBy('warehouse_id')
                        ->orderBy('stock_condition')
                        ->get();

                    foreach ($aggregateGrains as $aggregate) {
                        $checked++;
                        $lotTotals = DB::table('inventory_lot_balances as balances')
                            ->join('inventory_lots as lots', 'lots.id', '=', 'balances.inventory_lot_id')
                            ->whereNull('lots.canonical_inventory_lot_id')
                            ->where('lots.product_variant_id', $variant->getKey())
                            ->where('balances.warehouse_id', $aggregate->warehouse_id)
                            ->where('balances.stock_condition', $aggregate->stock_condition->value)
                            ->selectRaw(
                                'COALESCE(SUM(balances.on_hand_base_quantity), 0) as on_hand, '
                                .'COALESCE(SUM(balances.reserved_base_quantity), 0) as reserved',
                            )
                            ->first();

                        $lotOnHand = $this->decimal((string) ($lotTotals?->on_hand ?? '0'));
                        $lotReserved = $this->decimal((string) ($lotTotals?->reserved ?? '0'));

                        if (
                            bccomp($lotOnHand, (string) $aggregate->on_hand_base_quantity, 6) !== 0
                            || bccomp($lotReserved, (string) $aggregate->reserved_base_quantity, 6) !== 0
                        ) {
                            $errors[] = sprintf(
                                'Variant %d / warehouse %d / %s: aggregate=%s/%s, lots=%s/%s.',
                                $variant->getKey(),
                                $aggregate->warehouse_id,
                                $aggregate->stock_condition->value,
                                (string) $aggregate->on_hand_base_quantity,
                                (string) $aggregate->reserved_base_quantity,
                                $lotOnHand,
                                $lotReserved,
                            );
                        }
                    }

                    $orphanLotGrains = DB::table('inventory_lot_balances as balances')
                        ->join('inventory_lots as lots', 'lots.id', '=', 'balances.inventory_lot_id')
                        ->leftJoin('inventory_condition_balances as aggregate', function ($join): void {
                            $join->on('aggregate.product_variant_id', '=', 'lots.product_variant_id')
                                ->on('aggregate.warehouse_id', '=', 'balances.warehouse_id')
                                ->on('aggregate.stock_condition', '=', 'balances.stock_condition');
                        })
                        ->whereNull('lots.canonical_inventory_lot_id')
                        ->where('lots.product_variant_id', $variant->getKey())
                        ->whereNull('aggregate.id')
                        ->where(function ($query): void {
                            $query->where('balances.on_hand_base_quantity', '!=', 0)
                                ->orWhere('balances.reserved_base_quantity', '!=', 0);
                        })
                        ->get(['balances.inventory_lot_id', 'balances.warehouse_id', 'balances.stock_condition']);

                    foreach ($orphanLotGrains as $grain) {
                        $errors[] = sprintf(
                            'Lot %d / warehouse %d / %s has quantity without an aggregate condition balance.',
                            $grain->inventory_lot_id,
                            $grain->warehouse_id,
                            $grain->stock_condition,
                        );
                    }
                }
            });

        return $checked;
    }

    /** @param list<string> &$errors */
    private function checkReservationReconciliation(array &$errors): int
    {
        $checked = 0;

        $lotReservationGrains = InventoryLotBalance::query()
            ->where('stock_condition', StockCondition::Saleable->value)
            ->where('reserved_base_quantity', '!=', 0)
            ->get();

        foreach ($lotReservationGrains as $balance) {
            $checked++;
            $allocated = DB::table('inventory_reservation_allocations as allocations')
                ->join('inventory_reservations as reservations', 'reservations.id', '=', 'allocations.inventory_reservation_id')
                ->where('reservations.status', ReservationStatus::Active->value)
                ->where('reservations.warehouse_id', $balance->warehouse_id)
                ->where('allocations.inventory_lot_id', $balance->inventory_lot_id)
                ->sum('allocations.base_quantity');

            $allocated = $this->decimal((string) $allocated);

            if (bccomp($allocated, (string) $balance->reserved_base_quantity, 6) !== 0) {
                $errors[] = sprintf(
                    'Lot %d / warehouse %d reserved=%s but active allocations=%s.',
                    $balance->inventory_lot_id,
                    $balance->warehouse_id,
                    (string) $balance->reserved_base_quantity,
                    $allocated,
                );
            }
        }

        $activeAllocatedGrains = DB::table('inventory_reservation_allocations as allocations')
            ->join('inventory_reservations as reservations', 'reservations.id', '=', 'allocations.inventory_reservation_id')
            ->where('reservations.status', ReservationStatus::Active->value)
            ->whereNotNull('allocations.inventory_lot_id')
            ->groupBy('allocations.inventory_lot_id', 'reservations.warehouse_id')
            ->selectRaw(
                'allocations.inventory_lot_id, reservations.warehouse_id, '
                .'SUM(allocations.base_quantity) as allocated',
            )
            ->get();

        foreach ($activeAllocatedGrains as $grain) {
            $exists = InventoryLotBalance::query()
                ->where('inventory_lot_id', $grain->inventory_lot_id)
                ->where('warehouse_id', $grain->warehouse_id)
                ->where('stock_condition', StockCondition::Saleable->value)
                ->where('reserved_base_quantity', '!=', 0)
                ->exists();

            if (! $exists) {
                $errors[] = sprintf(
                    'Active reservation allocation for lot %d / warehouse %d has no materialized saleable reservation.',
                    $grain->inventory_lot_id,
                    $grain->warehouse_id,
                );
            }
        }

        return $checked;
    }

    /** @param list<string> &$errors */
    private function checkSerialReconciliation(array &$errors): int
    {
        $checked = 0;

        SerializedInventoryUnit::query()
            ->whereNotNull('inventory_lot_id')
            ->with('lot:id,product_variant_id,canonical_inventory_lot_id')
            ->orderBy('id')
            ->chunkById(200, function ($units) use (&$errors, &$checked): void {
                foreach ($units as $unit) {
                    $checked++;

                    if (! $unit->lot instanceof InventoryLot) {
                        $errors[] = sprintf('Serialized unit %d references a missing lot.', $unit->getKey());

                        continue;
                    }

                    if (
                        $unit->lot->canonical_inventory_lot_id !== null
                        || $unit->lot->product_variant_id !== $unit->product_variant_id
                    ) {
                        $errors[] = sprintf(
                            'Serialized unit %d does not reference its canonical variant lot identity.',
                            $unit->getKey(),
                        );
                    }

                    if ($unit->custody_type !== SerializedCustodyType::Warehouse) {
                        if ($unit->warehouse_id !== null) {
                            $errors[] = sprintf(
                                'Serialized unit %d has non-warehouse custody but retains warehouse %d.',
                                $unit->getKey(),
                                $unit->warehouse_id,
                            );
                        }

                        continue;
                    }

                    if ($unit->warehouse_id === null || ! $unit->stock_condition->isMaterialized()) {
                        $errors[] = sprintf(
                            'Serialized unit %d has invalid warehouse custody/condition.',
                            $unit->getKey(),
                        );

                        continue;
                    }

                    $balance = InventoryLotBalance::query()
                        ->where('inventory_lot_id', $unit->inventory_lot_id)
                        ->where('warehouse_id', $unit->warehouse_id)
                        ->where('stock_condition', $unit->stock_condition->value)
                        ->first();

                    if (! $balance instanceof InventoryLotBalance || bccomp((string) $balance->on_hand_base_quantity, '1', 6) < 0) {
                        $errors[] = sprintf(
                            'Serialized unit %d has no matching positive lot balance at its warehouse/condition.',
                            $unit->getKey(),
                        );
                    }
                }
            });

        ProductVariant::query()
            ->where('track_serials', true)
            ->orderBy('id')
            ->chunkById(100, function ($variants) use (&$errors): void {
                foreach ($variants as $variant) {
                    $serialGrains = SerializedInventoryUnit::query()
                        ->where('product_variant_id', $variant->getKey())
                        ->where('custody_type', SerializedCustodyType::Warehouse->value)
                        ->whereNotNull('warehouse_id')
                        ->selectRaw('warehouse_id, stock_condition, COUNT(*) as unit_count')
                        ->groupBy('warehouse_id', 'stock_condition')
                        ->get();

                    foreach ($serialGrains as $grain) {
                        if ($grain->stock_condition === StockCondition::Disposed->value) {
                            continue;
                        }

                        $aggregate = InventoryConditionBalance::query()
                            ->where('product_variant_id', $variant->getKey())
                            ->where('warehouse_id', $grain->warehouse_id)
                            ->where('stock_condition', $grain->stock_condition)
                            ->first();

                        $aggregateOnHand = $aggregate instanceof InventoryConditionBalance
                            ? $this->decimal((string) $aggregate->on_hand_base_quantity)
                            : '0.000000';
                        $serialCount = $this->decimal((string) $grain->unit_count);

                        if (bccomp($aggregateOnHand, $serialCount, 6) !== 0) {
                            $errors[] = sprintf(
                                'Serialized variant %d / warehouse %d / %s: aggregate=%s, serial custody count=%s.',
                                $variant->getKey(),
                                $grain->warehouse_id,
                                $grain->stock_condition,
                                $aggregateOnHand,
                                $serialCount,
                            );
                        }
                    }
                }
            });

        return $checked;
    }

    /** @param list<string> &$errors */
    private function checkReturnReconciliation(array &$errors): int
    {
        $checked = 0;

        InventoryReturnLine::query()
            ->with('inventoryReturn:id,return_type,status')
            ->orderBy('id')
            ->chunkById(200, function ($lines) use (&$errors, &$checked): void {
                foreach ($lines as $line) {
                    $checked++;
                    $return = $line->inventoryReturn;

                    if ($return === null) {
                        $errors[] = sprintf('Inventory return line %d has no return header.', $line->getKey());

                        continue;
                    }

                    $isPosted = $return->status === InventoryReturnStatus::Posted;
                    $hasPostingReference = $line->posted_inventory_movement_id !== null;
                    $postedQuantity = $this->decimal((string) $line->posted_base_quantity);

                    if (! $isPosted) {
                        if ($hasPostingReference || bccomp($postedQuantity, '0', 6) !== 0) {
                            $errors[] = sprintf(
                                'Unposted inventory return line %d contains posted movement evidence.',
                                $line->getKey(),
                            );
                        }

                        continue;
                    }

                    if (
                        ! $hasPostingReference
                        || bccomp($postedQuantity, (string) $line->base_quantity, 6) !== 0
                    ) {
                        $errors[] = sprintf(
                            'Posted inventory return line %d does not reconcile its posted quantity/movement reference.',
                            $line->getKey(),
                        );

                        continue;
                    }

                    $movement = InventoryMovement::query()->find($line->posted_inventory_movement_id);

                    if (
                        ! $movement instanceof InventoryMovement
                        || $movement->movement_type !== MovementType::Return
                        || $movement->source_type !== 'inventory_return'
                        || $movement->source_id !== $return->getKey()
                        || $movement->source_line_type !== 'inventory_return_line'
                        || $movement->source_line_id !== $line->getKey()
                    ) {
                        $errors[] = sprintf(
                            'Posted inventory return line %d does not point to its canonical Return movement.',
                            $line->getKey(),
                        );

                        continue;
                    }

                    $expectedMovementQuantity = $return->return_type === InventoryReturnType::Customer
                        ? $this->decimal((string) $line->base_quantity)
                        : bcsub('0', $this->decimal((string) $line->base_quantity), 6);

                    if (bccomp((string) $movement->quantity, $expectedMovementQuantity, 6) !== 0) {
                        $errors[] = sprintf(
                            'Inventory return line %d movement sign/quantity is incorrect: expected %s, got %s.',
                            $line->getKey(),
                            $expectedMovementQuantity,
                            (string) $movement->quantity,
                        );
                    }
                }
            });

        return $checked;
    }

    /** @param list<string> &$errors */
    private function checkCanonicalIdentityReferences(array &$errors): void
    {
        $aliases = InventoryLot::query()
            ->whereNotNull('canonical_inventory_lot_id')
            ->pluck('id');

        if ($aliases->isEmpty()) {
            return;
        }

        $aliasIds = $aliases->all();

        if (InventoryLotBalance::query()->whereIn('inventory_lot_id', $aliasIds)->exists()) {
            $errors[] = 'Legacy lot aliases still own materialized lot balances.';
        }

        foreach ([
            'inventory_operation_lines' => [
                'inventory_lot_id',
                'source_inventory_lot_id',
                'destination_inventory_lot_id',
            ],
            'inventory_movements' => ['inventory_lot_id'],
            'inventory_reservation_allocations' => ['inventory_lot_id'],
            'inventory_adjustment_items' => ['inventory_lot_id'],
            'service_record_parts' => ['inventory_lot_id'],
            'serialized_inventory_units' => ['inventory_lot_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (
                    DB::getSchemaBuilder()->hasTable($table)
                    && DB::getSchemaBuilder()->hasColumn($table, $column)
                    && DB::table($table)->whereIn($column, $aliasIds)->exists()
                ) {
                    $errors[] = sprintf(
                        '%s.%s still references a legacy lot alias.',
                        $table,
                        $column,
                    );
                }
            }
        }
    }

    /** @return numeric-string */
    private function decimal(string $value): string
    {
        return bcadd(is_numeric($value) ? $value : '0', '0', 6);
    }
}
