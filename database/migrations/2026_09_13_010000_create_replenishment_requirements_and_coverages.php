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
            $table->unsignedBigInteger('warehouse_replenishment_policy_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('required_base_quantity', 20, 6);
            $table->decimal('covered_base_quantity', 20, 6)->default(0);
            $table->decimal('fulfilled_base_quantity', 20, 6)->default(0);
            $table->string('status', 32)->default('open');
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('warehouse_replenishment_policy_id', 'repl_req_policy_fk')
                ->references('id')->on('warehouse_replenishment_policies')->restrictOnDelete();
            $table->foreign('warehouse_id', 'repl_req_warehouse_fk')
                ->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('product_variant_id', 'repl_req_variant_fk')
                ->references('id')->on('product_variants')->restrictOnDelete();
            $table->foreign('created_by', 'repl_req_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'repl_req_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['warehouse_id', 'product_variant_id', 'status'], 'repl_req_stock_status_idx');
        });

        Schema::create('replenishment_coverages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('replenishment_requirement_id');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->decimal('covered_base_quantity', 20, 6);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->foreign('replenishment_requirement_id', 'repl_cov_requirement_fk')
                ->references('id')->on('replenishment_requirements')->cascadeOnDelete();
            $table->unique(['replenishment_requirement_id', 'source_type', 'source_id'], 'repl_cov_source_uq');
            $table->index(['source_type', 'source_id', 'status'], 'repl_cov_source_status_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE replenishment_requirements ADD COLUMN active_policy_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('open','partially_covered','covered') THEN warehouse_replenishment_policy_id ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX repl_req_active_policy_uq ON replenishment_requirements (active_policy_id)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT repl_req_required_positive CHECK (required_base_quantity > 0)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT repl_req_covered_non_negative CHECK (covered_base_quantity >= 0)');
            DB::statement('ALTER TABLE replenishment_requirements ADD CONSTRAINT repl_req_fulfilled_non_negative CHECK (fulfilled_base_quantity >= 0)');
            DB::statement('ALTER TABLE replenishment_coverages ADD CONSTRAINT repl_cov_quantity_positive CHECK (covered_base_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_coverages');
        Schema::dropIfExists('replenishment_requirements');
    }
};
