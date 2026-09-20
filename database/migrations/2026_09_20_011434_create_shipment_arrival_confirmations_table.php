<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only delivery-confirmation evidence for a Shipment. `confirmed_by_id`
 * is polymorphic by `confirmed_by_type` (a CustomerProfile or a User, or null
 * for System) exactly like `shipments.confirmed_by_id`/`confirmed_by_type`
 * already are, so it carries no single FK constraint either.
 *
 * One row per shipment (`unique('shipment_id')`): a repeated confirmation
 * call is a no-op rather than a new row, so evidence is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_arrival_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('confirmed_by_type', 20);
            $table->unsignedBigInteger('confirmed_by_id')->nullable();
            $table->timestamp('confirmed_at');
            $table->string('source_channel', 30)->default('dashboard');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_arrival_confirmations');
    }
};
