<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->decimal('on_hand_quantity', 20, 6)->default(0)->change();
            $table->decimal('reserved_quantity', 20, 6)->default(0)->change();
            $table->decimal('damaged_quantity', 20, 6)->default(0)->change();
            $table->decimal('available_quantity', 20, 6)->default(0)->change();
            $table->decimal('reorder_level', 20, 6)->nullable()->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('quantity', 20, 6)->change();
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->decimal('on_hand_quantity', 20, 6)->default(0)->change();
            $table->decimal('reserved_quantity', 20, 6)->default(0)->change();
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 20, 6)->change();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('quantity_ordered', 20, 6)->change();
            $table->decimal('quantity_received', 20, 6)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('quantity_ordered', 15, 3)->change();
            $table->decimal('quantity_received', 15, 3)->default(0)->change();
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->change();
        });

        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->decimal('on_hand_quantity', 15, 3)->default(0)->change();
            $table->decimal('reserved_quantity', 15, 3)->default(0)->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->change();
        });

        Schema::table('inventory_stocks', function (Blueprint $table): void {
            $table->decimal('on_hand_quantity', 15, 3)->default(0)->change();
            $table->decimal('reserved_quantity', 15, 3)->default(0)->change();
            $table->decimal('damaged_quantity', 15, 3)->default(0)->change();
            $table->decimal('available_quantity', 15, 3)->default(0)->change();
            $table->decimal('reorder_level', 15, 3)->nullable()->change();
        });
    }
};
