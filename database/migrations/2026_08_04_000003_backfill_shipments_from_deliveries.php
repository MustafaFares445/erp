<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_operations')
            ->where('operation_type', 'delivery')
            ->where('source_document_type', 'App\\Models\\Order')
            ->whereNotNull('source_document_id')
            ->orderBy('id')
            ->get(['id', 'source_document_id', 'source_warehouse_id', 'tracking_number', 'created_at', 'updated_at'])
            ->each(static function (object $delivery): void {
                if (DB::table('shipments')->where('inventory_operation_id', $delivery->id)->exists()) {
                    return;
                }

                DB::table('shipments')->insert([
                    'order_id' => $delivery->source_document_id,
                    'inventory_operation_id' => $delivery->id,
                    'warehouse_id' => $delivery->source_warehouse_id,
                    'tracking_number' => $delivery->tracking_number ?? 'TRK-LEGACY-DELIVERY-'.mb_str_pad((string) $delivery->id, 6, '0', STR_PAD_LEFT),
                    'status' => 'in_transit',
                    'created_at' => $delivery->created_at ?? now(),
                    'updated_at' => $delivery->updated_at ?? now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('shipments')
            ->whereNotNull('inventory_operation_id')
            ->delete();
    }
};
