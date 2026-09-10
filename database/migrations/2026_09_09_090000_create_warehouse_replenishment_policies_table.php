<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces `inventory_stocks.reorder_level` (Phase 0 remediation): mixing
 * current stock state with replenishment policy meant a policy could not be
 * defined for a warehouse/variant with zero existing stock. A policy is now
 * its own record, independent of whether a matching `inventory_stocks` row
 * exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_replenishment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('min_quantity', 20, 6);
            $table->decimal('max_quantity', 20, 6);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id'], 'warehouse_replenishment_policies_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE warehouse_replenishment_policies ADD CONSTRAINT warehouse_replenishment_policies_min_non_negative CHECK (min_quantity >= 0)');
            DB::statement('ALTER TABLE warehouse_replenishment_policies ADD CONSTRAINT warehouse_replenishment_policies_max_above_min CHECK (max_quantity > min_quantity)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_replenishment_policies');
    }
};
