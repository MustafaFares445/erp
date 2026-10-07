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
        DB::table('quotations')
            ->where('status', 'converted_to_delivery')
            ->update(['status' => 'converted_to_order']);

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->foreignId('serialized_inventory_unit_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('serialized_inventory_units')
                ->nullOnDelete();

            $table->index(
                ['customer_id', 'serialized_inventory_unit_id'],
                'customer_visits_customer_equipment_index',
            );
        });

        Schema::table('plan_tasks', function (Blueprint $table): void {
            $table->foreignId('sales_opportunity_id')
                ->nullable()
                ->after('source_visit_id')
                ->constrained('sales_opportunities')
                ->nullOnDelete();
            $table->foreignId('quotation_id')
                ->nullable()
                ->after('sales_opportunity_id')
                ->constrained('quotations')
                ->nullOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->after('quotation_id')
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('serialized_inventory_unit_id')
                ->nullable()
                ->after('order_id')
                ->constrained('serialized_inventory_units')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plan_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_opportunity_id');
            $table->dropConstrainedForeignId('quotation_id');
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
        });

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->dropIndex('customer_visits_customer_equipment_index');
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
        });

        DB::table('quotations')
            ->where('status', 'converted_to_order')
            ->update(['status' => 'converted_to_delivery']);
    }
};
