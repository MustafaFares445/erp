<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_item_number', 100);
            $table->string('country_code', 2)->nullable()->index();
            $table->string('manufacturer')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_id', 'supplier_item_number'], 'supplier_reference_item_unique');
            $table->index(['product_variant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_references');
    }
};
