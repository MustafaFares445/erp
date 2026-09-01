<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertStockConditionBackfillIsValid();
        $this->assertLotConditionBackfillIsValid();
        $this->assertLotConditionBackfillIsUnambiguous();

        Schema::create('inventory_condition_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('stock_condition', 20);
            $table->decimal('on_hand_base_quantity', 20, 6)->default(0);
            $table->decimal('reserved_base_quantity', 20, 6)->default(0);
            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'warehouse_id', 'stock_condition'],
                'inventory_condition_balance_grain_unique',
            );
            $table->index(
                ['warehouse_id', 'stock_condition', 'product_variant_id'],
                'inventory_condition_balance_lookup_index',
            );
        });

        Schema::create('inventory_lot_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('stock_condition', 20);
            $table->decimal('on_hand_base_quantity', 20, 6)->default(0);
            $table->decimal('reserved_base_quantity', 20, 6)->default(0);
            $table->timestamps();

            $table->unique(
                ['inventory_lot_id', 'warehouse_id', 'stock_condition'],
                'inventory_lot_balance_grain_unique',
            );
            $table->index(
                ['warehouse_id', 'stock_condition', 'inventory_lot_id'],
                'inventory_lot_balance_lookup_index',
            );
        });

        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->string('stock_condition', 20)
                ->default('saleable')
                ->after('custody_type')
                ->index();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('stock_condition_from', 20)->nullable()->after('base_quantity_delta');
            $table->string('stock_condition_to', 20)->nullable()->after('stock_condition_from');
            $table->decimal('condition_from_on_hand_before', 20, 6)->nullable();
            $table->decimal('condition_from_on_hand_after', 20, 6)->nullable();
            $table->decimal('condition_from_reserved_before', 20, 6)->nullable();
            $table->decimal('condition_from_reserved_after', 20, 6)->nullable();
            $table->decimal('condition_to_on_hand_before', 20, 6)->nullable();
            $table->decimal('condition_to_on_hand_after', 20, 6)->nullable();
            $table->decimal('condition_to_reserved_before', 20, 6)->nullable();
            $table->decimal('condition_to_reserved_after', 20, 6)->nullable();
        });

        $this->backfillConditionBalances();
        $this->backfillLotBalances();
        $this->backfillSerializedConditions();
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropColumn([
                'stock_condition_from',
                'stock_condition_to',
                'condition_from_on_hand_before',
                'condition_from_on_hand_after',
                'condition_from_reserved_before',
                'condition_from_reserved_after',
                'condition_to_on_hand_before',
                'condition_to_on_hand_after',
                'condition_to_reserved_before',
                'condition_to_reserved_after',
            ]);
        });

        Schema::table('serialized_inventory_units', function (Blueprint $table): void {
            $table->dropColumn('stock_condition');
        });

        Schema::dropIfExists('inventory_lot_balances');
        Schema::dropIfExists('inventory_condition_balances');
    }

    private function assertStockConditionBackfillIsValid(): void
    {
        if (! Schema::hasTable('inventory_stocks')) {
            return;
        }

        DB::table('inventory_stocks')->orderBy('id')->get()->each(function (object $stock): void {
            $onHand = $this->decimal($stock->on_hand_quantity);
            $reserved = $this->decimal($stock->reserved_quantity);
            $damaged = $this->decimal($stock->damaged_quantity ?? '0');
            $saleable = bcsub($onHand, $damaged, 6);

            if (
                bccomp($saleable, '0', 6) < 0
                || bccomp($reserved, '0', 6) < 0
                || bccomp($reserved, $saleable, 6) > 0
            ) {
                if (! is_numeric($stock->id)) {
                    throw new RuntimeException('Inventory stock backfill encountered a non-numeric id.');
                }

                throw new RuntimeException(sprintf(
                    'Inventory stock %s cannot be safely converted to condition balances. '
                    .'Run reconciliation and provide an explicit mapping before retrying.',
                    $stock->id,
                ));
            }
        });
    }

    private function assertLotConditionBackfillIsValid(): void
    {
        if (! Schema::hasTable('inventory_lots')) {
            return;
        }

        DB::table('inventory_lots')->orderBy('id')->get()->each(function (object $lot): void {
            $onHand = $this->decimal($lot->on_hand_quantity);
            $reserved = $this->decimal($lot->reserved_quantity);

            if (
                bccomp($onHand, '0', 6) < 0
                || bccomp($reserved, '0', 6) < 0
                || bccomp($reserved, $onHand, 6) > 0
            ) {
                if (! is_numeric($lot->id)) {
                    throw new RuntimeException('Inventory lot backfill encountered a non-numeric id.');
                }

                throw new RuntimeException(sprintf(
                    'Inventory lot %s cannot be safely converted to condition balances. '
                    .'Run reconciliation before retrying.',
                    $lot->id,
                ));
            }
        });
    }

    private function assertLotConditionBackfillIsUnambiguous(): void
    {
        if (! Schema::hasTable('inventory_stocks') || ! Schema::hasTable('inventory_lots')) {
            return;
        }

        $ambiguous = DB::table('inventory_stocks as stocks')
            ->join('inventory_lots as lots', function (JoinClause $join): void {
                $join->on('lots.product_variant_id', '=', 'stocks.product_variant_id')
                    ->on('lots.warehouse_id', '=', 'stocks.warehouse_id');
            })
            ->where('stocks.damaged_quantity', '>', 0)
            ->select('stocks.id', 'stocks.product_variant_id', 'stocks.warehouse_id', 'stocks.damaged_quantity')
            ->first();

        if ($ambiguous !== null) {
            if (
                ! is_numeric($ambiguous->damaged_quantity)
                || ! is_numeric($ambiguous->product_variant_id)
                || ! is_numeric($ambiguous->warehouse_id)
            ) {
                throw new RuntimeException('Inventory stock/lot ambiguity check encountered non-numeric values.');
            }

            throw new RuntimeException(sprintf(
                'Cannot infer which lot owns %s damaged base quantity for variant %s in warehouse %s. '
                .'Provide an explicit condition/lot mapping or use the approved development reset. '
                .'DEVELOPMENT DATABASE RESET RECOMMENDED.',
                $ambiguous->damaged_quantity,
                $ambiguous->product_variant_id,
                $ambiguous->warehouse_id,
            ));
        }
    }

    private function backfillConditionBalances(): void
    {
        DB::table('inventory_stocks')->orderBy('id')->get()->each(function (object $stock): void {
            $onHand = $this->decimal($stock->on_hand_quantity);
            $reserved = $this->decimal($stock->reserved_quantity);
            $damaged = $this->decimal($stock->damaged_quantity ?? '0');
            $saleable = bcsub($onHand, $damaged, 6);

            if (
                bccomp($saleable, '0', 6) < 0
                || bccomp($reserved, '0', 6) < 0
                || bccomp($reserved, $saleable, 6) > 0
            ) {
                if (! is_numeric($stock->id)) {
                    throw new RuntimeException('Inventory stock backfill encountered a non-numeric id.');
                }

                throw new RuntimeException(sprintf(
                    'Inventory stock %s cannot be safely converted to condition balances. '
                    .'Run reconciliation and provide a mapping before retrying.',
                    $stock->id,
                ));
            }

            foreach ([
                'saleable' => [$saleable, $reserved],
                'quarantine' => ['0.000000', '0.000000'],
                'damaged' => [$damaged, '0.000000'],
            ] as $condition => [$conditionOnHand, $conditionReserved]) {
                DB::table('inventory_condition_balances')->insert([
                    'product_variant_id' => $stock->product_variant_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'stock_condition' => $condition,
                    'on_hand_base_quantity' => $conditionOnHand,
                    'reserved_base_quantity' => $conditionReserved,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function backfillLotBalances(): void
    {
        DB::table('inventory_lots')->orderBy('id')->get()->each(function (object $lot): void {
            $onHand = $this->decimal($lot->on_hand_quantity);
            $reserved = $this->decimal($lot->reserved_quantity);

            if (bccomp($reserved, $onHand, 6) > 0) {
                if (! is_numeric($lot->id)) {
                    throw new RuntimeException('Inventory lot backfill encountered a non-numeric id.');
                }

                throw new RuntimeException(sprintf(
                    'Inventory lot %s has reserved quantity above on-hand and cannot be safely converted.',
                    $lot->id,
                ));
            }

            foreach ([
                'saleable' => [$onHand, $reserved],
                'quarantine' => ['0.000000', '0.000000'],
                'damaged' => ['0.000000', '0.000000'],
            ] as $condition => [$conditionOnHand, $conditionReserved]) {
                DB::table('inventory_lot_balances')->insert([
                    'inventory_lot_id' => $lot->id,
                    'warehouse_id' => $lot->warehouse_id,
                    'stock_condition' => $condition,
                    'on_hand_base_quantity' => $conditionOnHand,
                    'reserved_base_quantity' => $conditionReserved,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function backfillSerializedConditions(): void
    {
        DB::table('serialized_inventory_units')->orderBy('id')->get()->each(function (object $unit): void {
            $status = $unit->status;

            if (! is_string($status)) {
                throw new RuntimeException('Serialized inventory unit backfill encountered a non-string status.');
            }

            $condition = match ($status) {
                'damaged' => 'damaged',
                'disposed' => 'disposed',
                default => 'saleable',
            };

            DB::table('serialized_inventory_units')
                ->where('id', $unit->id)
                ->update(['stock_condition' => $condition]);
        });
    }

    /** @return numeric-string */
    private function decimal(mixed $quantity): string
    {
        if (! is_numeric($quantity)) {
            throw new RuntimeException('Inventory quantity backfill encountered a non-numeric value.');
        }

        return bcadd((string) $quantity, '0', 6);
    }
};
