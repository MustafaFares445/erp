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
        $knownOrderStatuses = [
            'draft', 'ready', 'pending_supplier_confirmation', 'supplier_rejected',
            'confirmed', 'released', 'closed', 'cancelled', 'canceled', 'completed',
        ];

        $unknownStatuses = DB::table('orders')
            ->whereNotIn('status', $knownOrderStatuses)
            ->distinct()
            ->pluck('status')
            ->filter()
            ->values();

        if ($unknownStatuses->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot safely migrate sales order statuses: '.implode(', ', $unknownStatuses->all()),
            );
        }

        DB::table('orders')->where('status', 'ready')->update([
            'status' => 'released',
            'confirmed_at' => DB::raw('COALESCE(confirmed_at, created_at)'),
            'released_at' => DB::raw('COALESCE(released_at, created_at)'),
        ]);
        DB::table('orders')->whereIn('status', ['pending_supplier_confirmation', 'supplier_rejected'])->update([
            'status' => 'released',
            'confirmed_at' => DB::raw('COALESCE(confirmed_at, created_at)'),
            'released_at' => DB::raw('COALESCE(released_at, created_at)'),
        ]);
        DB::table('orders')->where('status', 'confirmed')->whereNull('confirmed_at')->update([
            'confirmed_at' => DB::raw('created_at'),
        ]);
        DB::table('orders')->where('status', 'released')->whereNull('released_at')->update([
            'confirmed_at' => DB::raw('COALESCE(confirmed_at, created_at)'),
            'released_at' => DB::raw('created_at'),
        ]);
        DB::table('orders')->where('status', 'completed')->update([
            'status' => 'closed',
            'confirmed_at' => DB::raw('COALESCE(confirmed_at, created_at)'),
            'released_at' => DB::raw('COALESCE(released_at, created_at)'),
            'closed_at' => DB::raw('COALESCE(closed_at, updated_at)'),
        ]);
        DB::table('orders')->where('status', 'canceled')->update([
            'status' => 'cancelled',
            'cancelled_at' => DB::raw('COALESCE(cancelled_at, updated_at)'),
        ]);
        DB::table('orders')->where('status', 'cancelled')->whereNull('cancelled_at')->update([
            'cancelled_at' => DB::raw('updated_at'),
        ]);

        DB::table('shipments')
            ->where('status', 'in_transit')
            ->whereIn('inventory_operation_id', function ($query): void {
                $query->select('id')
                    ->from('inventory_operations')
                    ->where('operation_type', 'delivery')
                    ->where('stage', '!=', 'done');
            })
            ->update(['status' => 'planned']);

        $duplicateShipmentDelivery = DB::table('shipments')
            ->select('inventory_operation_id')
            ->whereNotNull('inventory_operation_id')
            ->groupBy('inventory_operation_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateShipmentDelivery !== null) {
            throw new RuntimeException('Cannot add shipment/delivery uniqueness: duplicate shipment links exist.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'scheduled_at'], 'orders_status_scheduled_at_index');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('status')->default('planned')->change();
            $table->unique('inventory_operation_id', 'shipments_inventory_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropUnique('shipments_inventory_operation_unique');
            $table->string('status')->default('in_transit')->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_scheduled_at_index');
        });
    }
};
