<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_attribute_value_id');
            $table->foreign('product_attribute_value_id', 'variant_attribute_value_fk')
                ->references('id')
                ->on('product_attribute_values')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_variant_id', 'product_attribute_value_id'], 'variant_attribute_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
