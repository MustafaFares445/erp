<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_floor_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('attempted_price', 15, 2);
            $table->decimal('min_price', 15, 2);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_floor_overrides');
    }
};
