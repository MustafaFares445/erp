<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_subscriptions', function (Blueprint $table): void {
            $table->foreignId('product_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_subscription_id', 'customer_profile_id']);
            $table->index(['customer_profile_id', 'product_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_subscriptions');
    }
};
