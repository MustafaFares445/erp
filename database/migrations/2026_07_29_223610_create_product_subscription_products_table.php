<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_subscription_products', function (Blueprint $table): void {
            $table->foreignId('product_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_subscription_id', 'product_id']);
            $table->index(['product_id', 'product_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_subscription_products');
    }
};
