<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_pricing_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pricing_tier_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_user_id', 'pricing_tier_id'], 'customer_pricing_tier_unique');
            $table->index(['customer_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pricing_tiers');
    }
};
