<?php

declare(strict_types=1);

use App\Models\InventoryLot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLotIdentityBackfillIsSafe();

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->string('normalized_lot_number', 100)->nullable()->after('lot_number');
            $table->unsignedBigInteger('canonical_inventory_lot_id')->nullable()->after('normalized_lot_number');
            $table->string('origin_source_type', 100)->nullable()->after('inventory_receipt_item_id');
            $table->unsignedBigInteger('origin_source_id')->nullable()->after('origin_source_type');
            $table->unsignedBigInteger('origin_source_line_id')->nullable()->after('origin_source_id');
            $table->index('canonical_inventory_lot_id', 'inventory_lots_canonical_alias_index');
            $table->index(
                ['origin_source_type', 'origin_source_id'],
                'inventory_lots_origin_source_index',
            );
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->foreign('canonical_inventory_lot_id')
                ->references('id')
                ->on('inventory_lots')
                ->restrictOnDelete();
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->nullOnDelete();
        });

        $this->canonicalizeLots();

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->unique(
                ['product_variant_id', 'normalized_lot_number'],
                'inventory_lots_variant_normalized_number_unique',
            );
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_lots')->whereNotNull('canonical_inventory_lot_id')->exists()) {
            throw new RuntimeException(
                'Canonical lot identity consolidation is forward-only once legacy lot aliases exist. '
                .'Restore from a pre-migration backup or use the approved development reset.',
            );
        }

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropUnique('inventory_lots_variant_normalized_number_unique');
            $table->dropForeign(['canonical_inventory_lot_id']);
            $table->dropIndex('inventory_lots_canonical_alias_index');
            $table->dropIndex('inventory_lots_origin_source_index');
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->restrictOnDelete();
            $table->dropColumn([
                'normalized_lot_number',
                'canonical_inventory_lot_id',
                'origin_source_type',
                'origin_source_id',
                'origin_source_line_id',
            ]);
        });
    }

    private function assertLotIdentityBackfillIsSafe(): void
    {
        if (! Schema::hasTable('inventory_lots') || ! Schema::hasTable('inventory_lot_balances')) {
            throw new RuntimeException(
                'Canonical lot identity requires inventory_lots and inventory_lot_balances. '
                .'Run the Phase 6 condition-balance migration first.',
            );
        }

        $groups = [];

        DB::table('inventory_lots')->orderBy('id')->get()->each(function (object $lot) use (&$groups): void {
            $normalized = $this->normalizeLotNumber($lot->lot_number);

            if ($normalized === null) {
                return;
            }

            $key = $lot->product_variant_id.'|'.$normalized;
            $expiry = $lot->expires_at === null ? '__NULL__' : (string) $lot->expires_at;
            $groups[$key]['expiries'][$expiry] = true;
            $groups[$key]['ids'][] = (int) $lot->id;
        });

        foreach ($groups as $key => $group) {
            if (count($group['expiries']) <= 1) {
                continue;
            }

            throw new RuntimeException(
                'Lot identity conflict for '.$key.': rows '.implode(', ', $group['ids'])
                .' normalize to one lot number but disagree on expiry. '
                .'Provide an explicit lot mapping or use the approved development reset. '
                .'DEVELOPMENT DATABASE RESET RECOMMENDED.',
            );
        }
    }

    private function canonicalizeLots(): void
    {
        $lots = DB::table('inventory_lots')->orderBy('id')->get();
        $groups = [];

        foreach ($lots as $lot) {
            $normalized = $this->normalizeLotNumber($lot->lot_number);

            DB::table('inventory_lots')->where('id', $lot->id)->update([
                'normalized_lot_number' => $normalized,
                'origin_source_type' => $lot->inventory_receipt_item_id === null
                    ? null
                    : 'legacy_inventory_receipt_item',
                'origin_source_id' => $lot->inventory_receipt_item_id,
                'origin_source_line_id' => null,
            ]);

            if ($normalized === null) {
                continue;
            }

            $groups[$lot->product_variant_id.'|'.$normalized][] = $lot;
        }

        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }

            usort($group, fn (object $left, object $right): int => (int) $left->id <=> (int) $right->id);
            $canonical = $group[0];
            $aliasIds = array_map(
                fn (object $lot): int => (int) $lot->id,
                array_slice($group, 1),
            );
            $allIds = [(int) $canonical->id, ...$aliasIds];

            $this->mergeLotBalances($allIds, (int) $canonical->id);
            $this->remapCanonicalReferences($aliasIds, (int) $canonical->id);

            $originReceiptItemId = $canonical->inventory_receipt_item_id;

            if ($originReceiptItemId === null) {
                foreach ($group as $candidate) {
                    if ($candidate->inventory_receipt_item_id !== null) {
                        $originReceiptItemId = $candidate->inventory_receipt_item_id;
                        break;
                    }
                }
            }

            DB::table('inventory_lots')
                ->where('id', $canonical->id)
                ->update([
                    'inventory_receipt_item_id' => $originReceiptItemId,
                    'origin_source_type' => $originReceiptItemId === null
                        ? null
                        : 'legacy_inventory_receipt_item',
                    'origin_source_id' => $originReceiptItemId,
                ]);

            DB::table('inventory_lots')
                ->whereIn('id', $aliasIds)
                ->update([
                    'normalized_lot_number' => null,
                    'canonical_inventory_lot_id' => $canonical->id,
                ]);
        }

        DB::table('inventory_lots')
            ->whereNull('canonical_inventory_lot_id')
            ->update([
                'warehouse_id' => null,
                'on_hand_quantity' => 0,
                'reserved_quantity' => 0,
            ]);
    }

    /** @param list<int> $lotIds */
    private function mergeLotBalances(array $lotIds, int $canonicalLotId): void
    {
        $aggregated = [];

        DB::table('inventory_lot_balances')
            ->whereIn('inventory_lot_id', $lotIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $balance) use (&$aggregated): void {
                $key = $balance->warehouse_id.'|'.$balance->stock_condition;

                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'warehouse_id' => (int) $balance->warehouse_id,
                        'stock_condition' => (string) $balance->stock_condition,
                        'on_hand' => '0.000000',
                        'reserved' => '0.000000',
                    ];
                }

                $aggregated[$key]['on_hand'] = bcadd(
                    $aggregated[$key]['on_hand'],
                    (string) $balance->on_hand_base_quantity,
                    6,
                );
                $aggregated[$key]['reserved'] = bcadd(
                    $aggregated[$key]['reserved'],
                    (string) $balance->reserved_base_quantity,
                    6,
                );
            });

        DB::table('inventory_lot_balances')->whereIn('inventory_lot_id', $lotIds)->delete();

        foreach ($aggregated as $balance) {
            if (
                bccomp($balance['on_hand'], '0', 6) < 0
                || bccomp($balance['reserved'], '0', 6) < 0
                || (
                    $balance['stock_condition'] === 'saleable'
                    && bccomp($balance['reserved'], $balance['on_hand'], 6) > 0
                )
                || (
                    $balance['stock_condition'] !== 'saleable'
                    && bccomp($balance['reserved'], '0', 6) !== 0
                )
            ) {
                throw new RuntimeException(
                    'Lot balance consolidation produced an invalid condition balance for canonical lot '
                    .$canonicalLotId.'. Run reconciliation before retrying.',
                );
            }

            DB::table('inventory_lot_balances')->insert([
                'inventory_lot_id' => $canonicalLotId,
                'warehouse_id' => $balance['warehouse_id'],
                'stock_condition' => $balance['stock_condition'],
                'on_hand_base_quantity' => $balance['on_hand'],
                'reserved_base_quantity' => $balance['reserved'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param list<int> $aliasIds */
    private function remapCanonicalReferences(array $aliasIds, int $canonicalLotId): void
    {
        if ($aliasIds === []) {
            return;
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
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereIn($column, $aliasIds)
                    ->update([$column => $canonicalLotId]);
            }
        }

        foreach ([
            'inventory_alerts',
            'activity_log',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'subject_type')) {
                continue;
            }
            if (! Schema::hasColumn($table, 'subject_id')) {
                continue;
            }
            DB::table($table)
                ->where('subject_type', InventoryLot::class)
                ->whereIn('subject_id', $aliasIds)
                ->update(['subject_id' => $canonicalLotId]);
        }
    }

    private function normalizeLotNumber(mixed $lotNumber): ?string
    {
        if (! is_string($lotNumber)) {
            return null;
        }

        $trimmed = mb_trim($lotNumber);

        if ($trimmed === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return mb_strtoupper($collapsed, 'UTF-8');
    }
};
