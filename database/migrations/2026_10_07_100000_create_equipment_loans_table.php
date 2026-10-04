<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('original_serialized_inventory_unit_id')->constrained('serialized_inventory_units', indexName: 'equipment_loans_original_unit_fk')->restrictOnDelete();
            $table->foreignId('loaner_serialized_inventory_unit_id')->constrained('serialized_inventory_units', indexName: 'equipment_loans_loaner_unit_fk')->restrictOnDelete();
            $table->string('status', 20)->default('reserved');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expected_return_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignId('issue_inventory_operation_id')->nullable()->constrained('inventory_operations', indexName: 'equipment_loans_issue_operation_fk')->nullOnDelete();
            $table->foreignId('return_inventory_operation_id')->nullable()->constrained('inventory_operations', indexName: 'equipment_loans_return_operation_fk')->nullOnDelete();
            $table->foreignId('issue_inventory_movement_id')->nullable()->constrained('inventory_movements', indexName: 'equipment_loans_issue_movement_fk')->nullOnDelete();
            $table->foreignId('return_inventory_movement_id')->nullable()->constrained('inventory_movements', indexName: 'equipment_loans_return_movement_fk')->nullOnDelete();
            $table->string('condition_out', 30)->nullable();
            $table->string('condition_in', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loaner_serialized_inventory_unit_id', 'status'], 'equipment_loans_loaner_status_index');
            $table->index(['customer_id', 'status'], 'equipment_loans_customer_status_index');
            $table->index('maintenance_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_loans');
    }
};
