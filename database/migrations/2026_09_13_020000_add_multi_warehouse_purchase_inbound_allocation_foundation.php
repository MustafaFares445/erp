<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string LEGACY_LINE_UNIQUE = 'purchase_inbound_allocations_purchase_inbound_line_id_unique';

    private const string LINE_WAREHOUSE_UNIQUE = 'purchase_inbound_allocations_line_warehouse_unique';

    private const string PROVENANCE_INDEX = 'inventory_operation_lines_purchase_inbound_allocation_id_index';

    public function up(): void
    {
        $duplicate = DB::table('purchase_inbound_allocations')
            ->select('purchase_inbound_line_id', 'warehouse_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('purchase_inbound_line_id', 'warehouse_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException(sprintf(
                'Cannot enable multi-warehouse inbound allocations: inbound line %s has %s allocations for warehouse %s.',
                (string) $duplicate->purchase_inbound_line_id,
                (string) $duplicate->aggregate,
                (string) $duplicate->warehouse_id,
            ));
        }

        Schema::table('purchase_inbound_allocations', function (Blueprint $table): void {
            $table->decimal('allocated_base_quantity', 20, 6)->nullable()->after('warehouse_id');
            $table->unique(
                ['purchase_inbound_line_id', 'warehouse_id'],
                self::LINE_WAREHOUSE_UNIQUE,
            );
        });

        // The composite unique starts with purchase_inbound_line_id, so the
        // existing FK remains indexed while the legacy one-line unique is removed.
        Schema::table('purchase_inbound_allocations', function (Blueprint $table): void {
            $table->dropUnique(self::LEGACY_LINE_UNIQUE);
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_inbound_allocation_id')
                ->nullable()
                ->after('purchase_order_line_id');

            $table->index('purchase_inbound_allocation_id', self::PROVENANCE_INDEX);
        });

        // Backfill only the Phase-0 shape the plan can prove: an inbound line
        // with exactly one allocation owns that line's full canonical base quantity.
        // Rows without a canonical PO-line base quantity remain NULL rather than
        // deriving a quantity from transaction UOM data during this migration.
        $inboundLines = DB::table('purchase_inbound_lines as inbound_line')
            ->join(
                'purchase_order_lines as purchase_line',
                'purchase_line.id',
                '=',
                'inbound_line.purchase_order_line_id',
            )
            ->whereNotNull('purchase_line.base_quantity')
            ->select(['inbound_line.id', 'purchase_line.base_quantity'])
            ->orderBy('inbound_line.id')
            ->cursor();

        foreach ($inboundLines as $inboundLine) {
            $allocationIds = DB::table('purchase_inbound_allocations')
                ->where('purchase_inbound_line_id', $inboundLine->id)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');

            if ($allocationIds->count() !== 1) {
                continue;
            }

            DB::table('purchase_inbound_allocations')
                ->where('id', $allocationIds->first())
                ->update(['allocated_base_quantity' => $inboundLine->base_quantity]);
        }

        // Backfill receipt provenance only when the canonical PO line and receipt
        // destination warehouse resolve to exactly one inbound allocation.
        $receiptLines = DB::table('inventory_operation_lines as operation_line')
            ->join(
                'inventory_operations as operation',
                'operation.id',
                '=',
                'operation_line.inventory_operation_id',
            )
            ->where('operation.operation_type', 'receipt')
            ->whereNotNull('operation_line.purchase_order_line_id')
            ->whereNotNull('operation.destination_warehouse_id')
            ->select([
                'operation_line.id',
                'operation_line.purchase_order_line_id',
                'operation.destination_warehouse_id',
            ])
            ->orderBy('operation_line.id')
            ->cursor();

        foreach ($receiptLines as $receiptLine) {
            $candidateIds = DB::table('purchase_inbound_allocations as allocation')
                ->join(
                    'purchase_inbound_lines as inbound_line',
                    'inbound_line.id',
                    '=',
                    'allocation.purchase_inbound_line_id',
                )
                ->where('inbound_line.purchase_order_line_id', $receiptLine->purchase_order_line_id)
                ->where('allocation.warehouse_id', $receiptLine->destination_warehouse_id)
                ->orderBy('allocation.id')
                ->limit(2)
                ->pluck('allocation.id');

            if ($candidateIds->count() !== 1) {
                continue;
            }

            DB::table('inventory_operation_lines')
                ->where('id', $receiptLine->id)
                ->update(['purchase_inbound_allocation_id' => $candidateIds->first()]);
        }

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->foreign('purchase_inbound_allocation_id')
                ->references('id')
                ->on('purchase_inbound_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $splitLine = DB::table('purchase_inbound_allocations')
            ->select('purchase_inbound_line_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('purchase_inbound_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($splitLine !== null) {
            throw new \RuntimeException(sprintf(
                'Cannot roll back multi-warehouse inbound allocations: inbound line %s has %s allocations.',
                (string) $splitLine->purchase_inbound_line_id,
                (string) $splitLine->aggregate,
            ));
        }

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropForeign(['purchase_inbound_allocation_id']);
            $table->dropIndex(self::PROVENANCE_INDEX);
            $table->dropColumn('purchase_inbound_allocation_id');
        });

        Schema::table('purchase_inbound_allocations', function (Blueprint $table): void {
            $table->unique('purchase_inbound_line_id', self::LEGACY_LINE_UNIQUE);
        });

        Schema::table('purchase_inbound_allocations', function (Blueprint $table): void {
            $table->dropUnique(self::LINE_WAREHOUSE_UNIQUE);
            $table->dropColumn('allocated_base_quantity');
        });
    }
};
