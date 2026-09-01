<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Historical migration marker for the retired receipt/transfer backfill window.
 *
 * The project is still pre-production and Phase 10 removed the abandoned legacy
 * receipt/transfer persistence. Fresh databases are now built directly on the
 * canonical InventoryOperation architecture, so there is intentionally nothing
 * left to copy here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty: canonical operations are the only runtime model.
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
