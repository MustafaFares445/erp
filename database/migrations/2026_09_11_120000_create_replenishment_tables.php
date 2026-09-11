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
        Schema::create('warehouse_replenishment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('min_quantity', 20, 6);
            $table->decimal('max_quantity', 20, 6);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_variant_id'],
                'warehouse_replenishment_policy_grain_unique',
            );
            $table->index(
                ['product_variant_id', 'is_active', 'warehouse_id'],
                'warehouse_replenishment_policy_lookup_index',
            );
        });

        Schema::create('replenishment_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_replenishment_policy_id')
                ->constrained('warehouse_replenishment_policies')
                ->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('required_base_quantity', 20, 6);
            $table->decimal('covered_base_quantity', 20, 6)->default(0);
            $table->decimal('fulfilled_base_quantity', 20, 6)->default(0);
            $table->string('status', 32)->default('open')->index();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['warehouse_id', 'product_variant_id', 'status'],
                'replenishment_requirement_work_queue_index',
            );
            $table->index(
                ['warehouse_replenishment_policy_id', 'status'],
                'replenishment_requirement_policy_status_index',
            );
        });

        Schema::create('replenishment_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replenishment_requirement_id')
                ->constrained('replenishment_requirements')
                ->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->decimal('covered_base_quantity', 20, 6);
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();

            $table->unique(
                ['replenishment_requirement_id', 'source_type', 'source_id'],
                'replenishment_coverage_source_unique',
            );
            $table->index(
                ['source_type', 'source_id', 'status'],
                'replenishment_coverage_source_lookup_index',
            );
        });

        $this->addQuantityChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_coverages');
        Schema::dropIfExists('replenishment_requirements');
        Schema::dropIfExists('warehouse_replenishment_policies');
    }

    private function addQuantityChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE warehouse_replenishment_policies '
            .'ADD CONSTRAINT warehouse_replenishment_policy_min_non_negative '
            .'CHECK (min_quantity >= 0)',
        );
        DB::statement(
            'ALTER TABLE warehouse_replenishment_policies '
            .'ADD CONSTRAINT warehouse_replenishment_policy_max_above_min '
            .'CHECK (max_quantity > min_quantity)',
        );
        DB::statement(
            'ALTER TABLE replenishment_requirements '
            .'ADD CONSTRAINT replenishment_requirement_quantities_non_negative '
            .'CHECK (required_base_quantity > 0 AND covered_base_quantity >= 0 AND fulfilled_base_quantity >= 0)',
        );
        DB::statement(
            'ALTER TABLE replenishment_coverages '
            .'ADD CONSTRAINT replenishment_coverage_quantity_positive '
            .'CHECK (covered_base_quantity > 0)',
        );
    }
};
