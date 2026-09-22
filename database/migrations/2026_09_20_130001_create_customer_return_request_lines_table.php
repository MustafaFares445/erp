<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_request_id')
                ->constrained('customer_return_requests', 'id', 'crr_lines_request_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('original_inventory_operation_line_id')
                ->constrained('inventory_operation_lines', 'id', 'crr_lines_operation_line_id_foreign')
                ->restrictOnDelete();
            $table->decimal('requested_quantity', 20, 6);
            $table->text('customer_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_return_request_lines');
    }
};
