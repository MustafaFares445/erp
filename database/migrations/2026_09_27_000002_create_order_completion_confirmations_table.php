<?php

declare(strict_types=1);

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only final-completion evidence for an {@see Order}
 * (IERP_ORDER_CUSTOMER_CLOSE_PAYMENT_FLOW_IMPLEMENTATION_PLAN §7) — mirrors
 * `shipment_arrival_confirmations`: one row per order (`unique('order_id')`),
 * never edited or replaced, so a repeated confirmation call is a no-op
 * rather than a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_completion_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->timestamp('confirmed_at');
            $table->string('source_channel', 30)->default('customer_app');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_completion_confirmations');
    }
};
