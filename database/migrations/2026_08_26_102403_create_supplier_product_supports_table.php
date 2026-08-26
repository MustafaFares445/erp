<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_supports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active']);
            $table->index(['product_variant_id', 'is_active']);
            $table->unique(['supplier_id', 'product_id'], 'supplier_product_support_product_unique');
            $table->unique(['supplier_id', 'product_variant_id'], 'supplier_product_support_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_supports');
    }
};
