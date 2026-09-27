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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->boolean('supplier_confirmation_required')
                ->nullable()
                ->after('status')
                ->comment('Immutable supplier-confirmation policy snapshot taken when the PO is accepted.');
        });

        DB::table('purchase_orders')
            ->whereIn('status', ['accepted', 'partially_received', 'received', 'closed'])
            ->select(['id', 'supplier_id'])
            ->orderBy('id')
            ->chunkById(250, static function ($orders): void {
                $supplierIds = $orders->pluck('supplier_id')->filter()->unique()->values();
                $policies = DB::table('suppliers')
                    ->whereIn('id', $supplierIds)
                    ->pluck('requires_confirmation', 'id');

                foreach ($orders as $order) {
                    DB::table('purchase_orders')
                        ->where('id', $order->id)
                        ->update([
                            'supplier_confirmation_required' => (bool) ($policies[$order->supplier_id] ?? false),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('supplier_confirmation_required');
        });
    }
};
