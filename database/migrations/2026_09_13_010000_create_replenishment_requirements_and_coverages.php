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
        Schema::create('replenishment_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_replenishment_policy_id')->constrained('warehouse_replenishment_policies')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('required_base_quantity', 20, 6);
            $table->decimal('covered_base_quantity', 20, 6)->default(0);
            $table->decimal('fulfilled_base_quantity', 20, 6)->default(0);
            $table->string('status', 32)->default('open');
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'product_variant_id', 'status'], 'replenishment_requirements_stock_status');
        });

        Schema::create('replenishment_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replenishment_requirement_id')->constrained('replenishment_requirements')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->decimal('covered_base_quantity', 20, 6);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['replenishment_requirement_id', 'source_type', 'source_id'], 'replenishment_coverage_source_unique');
            $table->index(['source_type', 'source_id', 'status'], 'replenishment_coverage_source_status');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE replenishment_requirements ADD COLUMN active_policy_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('open','partially_covered','covered') THEN warehouse_replenishment_policy_id ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX replenishment_requirement_active_policy_unique ON replenishment_requirements (active_policy_id)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT replenishment_requirements_required_positive CHECK (required_base_quantity > 0)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT replenishment_requirements_covered_non_negative CHECK (covered_base_quantity >= 0)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT replenishment_requirements_fulfilled_non_negative CHECK (fulfilled_base_quantity >= 0)');
            DB::statement('ALTER TABLE replenishment_coverages ADD CONSTRAINT replenishment_coverages_quantity_positive CHECK (covered_base_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_coverages');
        Schema::dropIfExists('replenishment_requirements');
    }
};
