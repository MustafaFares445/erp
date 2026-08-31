<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_type', 100)->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->decimal('base_quantity', 20, 6);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('legacy_stock_reservation_id')->nullable()->unique();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['source_type', 'source_id']);
            $table->index(['product_variant_id', 'warehouse_id', 'status'], 'inventory_reservations_stock_status_index');
            $table->unique(
                ['source_type', 'source_id', 'source_line_type', 'source_line_id'],
                'inventory_reservations_source_line_unique',
            );
        });

        Schema::create('inventory_reservation_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->decimal('base_quantity', 20, 6);
            $table->timestamps();

            $table->index(['inventory_lot_id', 'inventory_reservation_id'], 'inventory_reservation_allocations_lot_index');
            $table->index(['serialized_inventory_unit_id', 'inventory_reservation_id'], 'inventory_reservation_allocations_serial_index');
        });

        $this->backfillLegacyReservations();
        $this->backfillReadyOperations();
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_allocations');
        Schema::dropIfExists('inventory_reservations');
    }

    private function backfillLegacyReservations(): void
    {
        if (! Schema::hasTable('stock_reservations')) {
            return;
        }

        DB::table('stock_reservations')->orderBy('id')->get()->each(function (object $legacy): void {
            $reservationId = DB::table('inventory_reservations')->insertGetId([
                'product_variant_id' => $legacy->product_variant_id,
                'warehouse_id' => $legacy->warehouse_id,
                'source_type' => $legacy->source_type,
                'source_id' => $legacy->source_id,
                'source_line_type' => null,
                'source_line_id' => null,
                'base_quantity' => $this->quantity((string) $legacy->quantity),
                'status' => $legacy->status,
                'expires_at' => $legacy->expires_at,
                'consumed_at' => null,
                'released_at' => in_array($legacy->status, ['released', 'expired'], true) ? ($legacy->updated_at ?? now()) : null,
                'legacy_stock_reservation_id' => $legacy->id,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => $legacy->updated_at ?? now(),
                'created_by' => $legacy->created_by,
                'updated_by' => $legacy->updated_by,
            ]);

            DB::table('inventory_reservation_allocations')->insert([
                'inventory_reservation_id' => $reservationId,
                'inventory_lot_id' => null,
                'serialized_inventory_unit_id' => null,
                'base_quantity' => $this->quantity((string) $legacy->quantity),
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => $legacy->updated_at ?? now(),
            ]);
        });
    }

    private function backfillReadyOperations(): void
    {
        if (! Schema::hasTable('inventory_operations') || ! Schema::hasTable('inventory_operation_lines')) {
            return;
        }

        DB::table('inventory_operations')
            ->where('stage', 'ready')
            ->whereIn('operation_type', ['delivery', 'internal_transfer'])
            ->orderBy('id')
            ->get()
            ->each(function (object $operation): void {
                if ($operation->source_warehouse_id === null) {
                    return;
                }

                DB::table('inventory_operation_lines')
                    ->where('inventory_operation_id', $operation->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $line) use ($operation): void {
                        $baseQuantity = $line->base_quantity;

                        if ($baseQuantity === null) {
                            $variantBaseUnitId = DB::table('product_variants')
                                ->where('id', $line->product_variant_id)
                                ->value('unit_id');

                            if ((int) $variantBaseUnitId !== (int) $line->unit_id) {
                                return;
                            }

                            $baseQuantity = $line->quantity;
                        }

                        $exists = DB::table('inventory_reservations')
                            ->where('source_type', 'inventory_operation')
                            ->where('source_id', $operation->id)
                            ->where('source_line_type', 'inventory_operation_line')
                            ->where('source_line_id', $line->id)
                            ->exists();

                        if ($exists) {
                            return;
                        }

                        $reservationId = DB::table('inventory_reservations')->insertGetId([
                            'product_variant_id' => $line->product_variant_id,
                            'warehouse_id' => $operation->source_warehouse_id,
                            'source_type' => 'inventory_operation',
                            'source_id' => $operation->id,
                            'source_line_type' => 'inventory_operation_line',
                            'source_line_id' => $line->id,
                            'base_quantity' => $this->quantity((string) $baseQuantity),
                            'status' => 'active',
                            'expires_at' => null,
                            'consumed_at' => null,
                            'released_at' => null,
                            'legacy_stock_reservation_id' => null,
                            'created_at' => $operation->created_at ?? now(),
                            'updated_at' => $operation->updated_at ?? now(),
                            'created_by' => $operation->created_by,
                            'updated_by' => $operation->updated_by,
                        ]);

                        DB::table('inventory_reservation_allocations')->insert([
                            'inventory_reservation_id' => $reservationId,
                            'inventory_lot_id' => $line->inventory_lot_id,
                            'serialized_inventory_unit_id' => $line->serialized_inventory_unit_id,
                            'base_quantity' => $this->quantity((string) $baseQuantity),
                            'created_at' => $operation->created_at ?? now(),
                            'updated_at' => $operation->updated_at ?? now(),
                        ]);
                    });
            });
    }

    private function quantity(string $quantity): string
    {
        return bcadd($quantity, '0', 6);
    }
};
