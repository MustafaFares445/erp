<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained('serialized_inventory_units')->nullOnDelete();
            $table->string('stock_condition', 20);
            $table->decimal('system_base_quantity', 18, 6);
            $table->decimal('counted_base_quantity', 18, 6)->nullable();
            $table->decimal('variance_base_quantity', 18, 6)->nullable();
            $table->bigInteger('variance_value_minor')->nullable();
            $table->boolean('recount_requested')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['inventory_count_id', 'product_variant_id', 'inventory_lot_id', 'serialized_inventory_unit_id', 'stock_condition'],
                'inventory_count_lines_grain_unique',
            );
            $table->index(['inventory_count_id', 'recount_requested']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
    }
};
