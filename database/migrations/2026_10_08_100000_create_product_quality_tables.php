<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_product_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('original_inventory_operation_line_id')->nullable()->constrained('inventory_operation_lines', indexName: 'ticket_product_contexts_delivery_line_fk')->nullOnDelete();
            $table->decimal('quantity', 14, 6)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('ticket_id');
            $table->index('inventory_lot_id');
            $table->index('product_variant_id');
        });

        Schema::create('ticket_quality_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('resolution_type', 30);
            $table->text('notes')->nullable();
            $table->foreignId('customer_return_request_id')->nullable()->constrained('customer_return_requests', indexName: 'ticket_quality_resolutions_return_request_fk')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->unique('ticket_id');
        });

        Schema::create('lot_quality_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->unsignedInteger('open_complaints');
            $table->unsignedInteger('threshold');
            $table->timestamp('raised_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('inventory_lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_quality_alerts');
        Schema::dropIfExists('ticket_quality_resolutions');
        Schema::dropIfExists('ticket_product_contexts');
    }
};
