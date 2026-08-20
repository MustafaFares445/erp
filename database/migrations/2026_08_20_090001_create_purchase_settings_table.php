<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton settings row for the purchasing module, following the
 * `inventory_settings` precedent (data-model.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_settings', function (Blueprint $table): void {
            $table->id();
            // Zero is the safe default: until the owner sets a value, every
            // submission routes to explicit approval rather than silently
            // auto-approving.
            $table->decimal('approval_threshold_amount', 15, 2)->default(0);
            $table->string('approval_threshold_currency', 3)->default('AED');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_settings');
    }
};
