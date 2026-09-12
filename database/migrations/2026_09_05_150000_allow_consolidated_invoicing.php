<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Introduces `invoice_delivery_links` as the control that a delivery is invoiced at most
     * once, across every invoice including standalone ones (WP-2.13, GAP-MW-13). `invoices`
     * never carries an `inventory_operation_id` column in this branch's schema history — it was
     * removed from its origin migration by WP-4.2 — so there is nothing to backfill; this join
     * table is the sole invoice-to-delivery relationship from the start.
     */
    public function up(): void
    {
        Schema::create('invoice_delivery_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('inventory_operation_id')->unique()->constrained('inventory_operations')->restrictOnDelete();
            $table->timestamps();
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_delivery_links');
    }
};
