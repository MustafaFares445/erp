<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('supplier_reference', 100)->nullable()->after('supplier_id');
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_reference')->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->after('purchase_order_id')->constrained('payment_terms')->nullOnDelete();
            $table->decimal('grand_total', 15, 2)->nullable()->after('total_amount');
            $table->decimal('paid_amount', 15, 2)->nullable()->after('amount_paid');
            $table->foreignId('journal_entry_id')->nullable()->after('paid_at')->constrained('journal_entries')->nullOnDelete();
            $table->index(['supplier_id', 'supplier_reference']);
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('requested_by')->nullable()->after('supplier_id')->constrained('employee_profiles')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->after('expense_account_id')->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('chart_account_id')->nullable()->after('payment_method_id')->constrained('chart_accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2)->nullable()->after('total_amount');
            $table->decimal('tax_amount', 15, 2)->nullable()->after('amount');
            $table->foreignId('journal_entry_id')->nullable()->after('paid_at')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::create('bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->text('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->index(['purchase_order_line_id', 'bill_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier_payment_number', 100)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date')->index();
            $table->string('reference', 150)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['supplier_payment_id', 'bill_id'], 'supplier_payment_bill_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('bill_lines');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['chart_account_id']);
            $table->dropForeign(['payment_method_id']);
            $table->dropForeign(['requested_by']);
            $table->dropColumn(['requested_by', 'payment_method_id', 'chart_account_id', 'amount', 'tax_amount', 'journal_entry_id']);
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id', 'supplier_reference']);
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['payment_term_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn(['supplier_reference', 'purchase_order_id', 'payment_term_id', 'grand_total', 'paid_amount', 'journal_entry_id']);
        });
    }
};
