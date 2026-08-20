<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records why a customer order is waiting on a supplier (FR-033, ERD extension
 * E-7).
 *
 * Deliberately the only column added. The ERD also lists `supplier_id`,
 * `payment_status`, and `grand_total` on `orders`, and all three are left to
 * the sales feature that defines their semantics (R-012) — adding unused
 * financial columns now would let them drift or be half-populated first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('pending_reason', 100)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('pending_reason');
        });
    }
};
