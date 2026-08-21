<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('inventory_operation_id')->nullable()->unique()->constrained('inventory_operations')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('tracking_number', 100)->unique();
            $table->string('status', 20)->default('in_transit')->index();
            $table->string('confirmed_by_type', 30)->nullable();
            $table->unsignedBigInteger('confirmed_by_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->index(['confirmed_by_type', 'confirmed_by_id'], 'shipments_confirmed_by_index');
            $table->index('confirmed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
