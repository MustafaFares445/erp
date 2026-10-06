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
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('orders')
                ->restrictOnDelete();
            $table->foreignId('sales_order_line_id')
                ->nullable()
                ->after('sales_order_id')
                ->constrained('order_lines')
                ->restrictOnDelete();

            $table->index(
                ['sales_order_id', 'status'],
                'inventory_reservations_sales_order_status_index',
            );
        });

        DB::table('inventory_reservations')
            ->where('source_type', 'inventory_operation')
            ->orderBy('id')
            ->chunkById(200, function ($reservations): void {
                foreach ($reservations as $reservation) {
                    $operation = DB::table('inventory_operations')
                        ->where('id', $reservation->source_id)
                        ->first(['source_document_type', 'source_document_id']);

                    if ($operation === null
                        || $operation->source_document_type !== 'App\\Models\\Order'
                        || ! is_numeric($operation->source_document_id)) {
                        continue;
                    }

                    $orderLineId = null;

                    if (is_numeric($reservation->source_line_id)) {
                        $candidate = DB::table('inventory_operation_lines')
                            ->where('id', $reservation->source_line_id)
                            ->value('order_line_id');

                        $orderLineId = is_numeric($candidate) ? (int) $candidate : null;
                    }

                    DB::table('inventory_reservations')
                        ->where('id', $reservation->id)
                        ->update([
                            'sales_order_id' => (int) $operation->source_document_id,
                            'sales_order_line_id' => $orderLineId,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_sales_order_status_index');
            $table->dropConstrainedForeignId('sales_order_line_id');
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
