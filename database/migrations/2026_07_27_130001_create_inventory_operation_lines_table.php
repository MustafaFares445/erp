<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_operation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained()->nullOnDelete();
            // No FK constraint yet: `packages` (US4, data-model.md §4-§5) does not exist until
            // this feature's Phase 6 runs. The constraint is added additively in the migration
            // that creates `packages`, alongside the other package_id columns in data-model.md §6.
            $table->unsignedBigInteger('package_id')->nullable();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_picked')->default(false);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->timestamps();

            $table->index('inventory_operation_id');
            $table->index('product_variant_id');
            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_operation_lines');
    }
};
