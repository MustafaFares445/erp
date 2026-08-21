<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_type', 30)->index();
            $table->string('stage', 30)->default('draft')->index();
            $table->string('operation_number', 100)->nullable()->unique();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('source_document', 'inventory_operations_source_document_index');
            $table->string('supplier_reference', 100)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('notes')->nullable();
            // Backfill provenance only (data-model.md §10). Dropped once
            // OperationBackfillReconciler verifies the legacy receipts/transfers
            // tables reconcile exactly against these rows.
            $table->unsignedBigInteger('legacy_receipt_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_transfer_id')->nullable()->unique();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->index(['operation_type', 'stage']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_operations');
    }
};
