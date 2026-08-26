<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per data-model.md §5 (research.md R-004). All six columns are nullable,
 * nothing is renamed or dropped, and no existing row is backfilled — a
 * pre-existing order has no price because pricing did not exist when it was
 * created, and `null` is the truthful value for that.
 *
 * `quotation_id` is unique so a quotation converts to at most one order from
 * this side too (FR-024, L-1); the reciprocal `quotations.converted_order_id`
 * unique index was added by the quotations migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('quotation_id')->nullable()->unique()->after('id')->constrained('quotations')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->after('quotation_id')->constrained('payment_terms')->restrictOnDelete();
            $table->decimal('subtotal', 15, 2)->nullable()->after('payment_term_id');
            $table->decimal('tax_total', 15, 2)->nullable()->after('subtotal');
            $table->decimal('grand_total', 15, 2)->nullable()->after('tax_total');
            $table->string('payment_status', 30)->nullable()->index()->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quotation_id');
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn(['subtotal', 'tax_total', 'grand_total', 'payment_status']);
        });
    }
};
