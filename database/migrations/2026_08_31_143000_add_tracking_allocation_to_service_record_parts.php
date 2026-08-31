<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_record_parts', function (Blueprint $table): void {
            $table->foreignId('inventory_lot_id')->nullable()->after('warehouse_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->after('inventory_lot_id')->constrained('serialized_inventory_units')->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->change();
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_record_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
            $table->dropConstrainedForeignId('inventory_lot_id');
            $table->decimal('quantity', 15, 3)->change();
            $table->unsignedBigInteger('inventory_movement_id')->nullable(false)->change();
        });
    }
};
