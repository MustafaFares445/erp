<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assigns one inbound line to the warehouse it will be received into.
 *
 * `purchase_inbound_line_id` is unique: Phase 0 allocates a whole line to one
 * warehouse rather than splitting it across several, which is the scope the
 * remediation plan itself calls out as good enough here ("enough to let an
 * Inventory Manager split an inbound's quantity across warehouses before
 * receiving" — the full suggested-allocation UX that would split one line
 * across warehouses is a later phase). Nothing about this shape blocks
 * widening the uniqueness later; the quantity reconciliation that matters for
 * over-receipt already lives on {@see PurchaseOrderLine}, keyed by line, not
 * by allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_inbound_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_inbound_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_inbound_allocations');
    }
};
