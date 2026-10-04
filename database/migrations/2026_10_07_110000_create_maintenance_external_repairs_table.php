<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_external_repairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->constrained('serialized_inventory_units', indexName: 'external_repairs_unit_fk')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('rma_number', 100)->nullable();
            $table->string('supplier_reference', 100)->nullable();
            $table->string('status', 30)->default('requested');
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('supplier_received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('outbound_reference', 100)->nullable();
            $table->string('inbound_reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('supplier_diagnosis')->nullable();
            $table->text('supplier_resolution')->nullable();
            $table->date('estimated_return_on')->nullable();
            $table->date('actual_return_on')->nullable();
            $table->foreignId('replacement_serialized_inventory_unit_id')->nullable()->constrained('serialized_inventory_units', indexName: 'external_repairs_replacement_unit_fk')->nullOnDelete();
            $table->foreignId('warranty_recovery_claim_id')->nullable()->constrained('warranty_recovery_claims', indexName: 'external_repairs_recovery_claim_fk')->nullOnDelete();
            $table->foreignId('ship_inventory_movement_id')->nullable()->constrained('inventory_movements', indexName: 'external_repairs_ship_movement_fk')->nullOnDelete();
            $table->foreignId('return_inventory_movement_id')->nullable()->constrained('inventory_movements', indexName: 'external_repairs_return_movement_fk')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['serialized_inventory_unit_id', 'status'], 'external_repairs_unit_status_index');
            $table->index(['supplier_id', 'status'], 'external_repairs_supplier_status_index');
            $table->index('maintenance_record_id');
        });

        Schema::table('warranty_recovery_claims', function (Blueprint $table): void {
            $table->string('recovery_outcome', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_recovery_claims', function (Blueprint $table): void {
            $table->dropColumn('recovery_outcome');
        });
        Schema::dropIfExists('maintenance_external_repairs');
    }
};
