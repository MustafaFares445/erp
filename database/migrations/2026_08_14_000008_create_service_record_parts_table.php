<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_record_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_task_id')->constrained('maintenance_tasks')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('inventory_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Append-only / immutable except reversed_* — no updated_at, matching TicketAssignment/TicketMessage.
            $table->timestamp('created_at')->nullable();

            $table->index('maintenance_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_record_parts');
    }
};
