<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores expiry capture on the unified operation document.
 *
 * `inventory_operation_lines` already carried `inventory_lot_id` for *outbound* lines, which
 * name an existing lot. An inbound line has no lot yet — it creates one — so it needs the
 * lot's identifying data on the line itself. Without these two columns a receipt confirmed
 * through the post-014 operation surface produced stock with no lot and no expiry date, while
 * the legacy `inventory_receipts` path rejected the same input (it has `lot_number` and
 * `expires_at` on its own items table). These columns close that divergence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->string('lot_number', 100)->nullable()->after('inventory_lot_id');
            $table->date('expires_at')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropColumn(['lot_number', 'expires_at']);
        });
    }
};
