<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_quotation_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_quotation_request_id')
                ->constrained('customer_quotation_requests', 'id', 'cqr_lines_request_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 20, 6);
            $table->foreignId('requested_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->text('customer_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_quotation_request_lines');
    }
};
