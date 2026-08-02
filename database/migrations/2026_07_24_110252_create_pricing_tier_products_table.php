<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_tier_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_tier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pricing_tier_id', 'product_id'], 'pricing_tier_product_unique');
            $table->index(['product_id', 'pricing_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tier_products');
    }
};
