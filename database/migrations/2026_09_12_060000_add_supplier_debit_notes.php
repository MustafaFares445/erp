<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->string('expected_outcome', 32)->nullable()->after('credit_note_required');
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->decimal('supplier_credit_total', 18, 2)->default(0)->after('amount_paid');
        });

        Schema::create('supplier_debit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('debit_note_number')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_return_id')->unique()->constrained('inventory_returns')->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();
            $table->date('issue_date');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 32)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['bill_id', 'status']);
        });

        Schema::create('supplier_debit_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_debit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_return_line_id')->constrained('inventory_return_lines')->restrictOnDelete();
            $table->foreignId('bill_line_id')->constrained('bill_lines')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();

            $table->unique(['supplier_debit_note_id', 'inventory_return_line_id'], 'supplier_debit_note_return_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debit_note_lines');
        Schema::dropIfExists('supplier_debit_notes');

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('supplier_credit_total');
        });

        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropColumn('expected_outcome');
        });
    }
};
