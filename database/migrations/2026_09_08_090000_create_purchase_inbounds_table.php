<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The warehouse-allocation aggregate created once a purchase order is
 * accepted (Phase 0 remediation).
 *
 * A purchase order no longer owns a destination warehouse (that ownership was
 * a blocking architectural defect: it forced a single warehouse onto every
 * line and made warehouse choice a drafting-time decision instead of a
 * post-acceptance one). This table, and {@see PurchaseInboundLine} /
 * {@see PurchaseInboundAllocation} beneath it, take over that responsibility.
 *
 * One row per accepted order (`purchase_order_id` unique): the aggregate has
 * no independent existence, so it is created idempotently by
 * `PurchaseInboundService::ensureForAccepted()` rather than by a form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_inbounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('awaiting_allocation')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('allocation_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_inbounds');
    }
};
