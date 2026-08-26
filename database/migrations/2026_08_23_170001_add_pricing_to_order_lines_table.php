<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per data-model.md §5 (research.md R-004). Nullable rather than `default 0`
 * is a correctness decision, not a convenience: a pre-existing line has no
 * price because pricing did not exist when it was written, and writing
 * `0.00` would assert the goods were free. The cost is paid at invoicing
 * time instead (FR-040), where a human is already deciding what to bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('line_total', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit_price', 'tax_amount', 'line_total']);
        });
    }
};
