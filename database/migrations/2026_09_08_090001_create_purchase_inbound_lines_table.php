<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties one purchase-order line to the inbound it belongs to
 * (`purchase_order_line_id` unique: a line is on exactly one inbound).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_inbound_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_inbound_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_inbound_lines');
    }
};
