<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serialized_inventory_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 255)->unique();
            $table->string('iot_number', 255)->nullable()->unique();
            $table->string('status', 30)->default('available')->index();
            $table->timestamps();

            $table->softDeletes();
            $table->index(['product_variant_id', 'warehouse_id'], 'serial_unit_variant_warehouse_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serialized_inventory_units');
    }
};
