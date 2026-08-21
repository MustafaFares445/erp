<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_receipt_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lot_number', 100)->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->decimal('on_hand_quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->index(['product_variant_id', 'warehouse_id', 'expires_at'], 'lot_variant_warehouse_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
