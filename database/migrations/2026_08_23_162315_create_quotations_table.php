<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per data-model.md §4. `converted_order_id` is unique so a quotation
 * converts to at most one order (FR-024, L-1); the reciprocal
 * `orders.quotation_id` unique index is added by the `orders` extension
 * migration for the same invariant from the other side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('quotation_number', 100)->unique();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employee_profiles')->restrictOnDelete();
            $table->foreignId('sales_opportunity_id')->nullable()->unique()->constrained('sales_opportunities')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('sent_at')->nullable();
            $table->date('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
