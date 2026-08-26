<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('inventory_operation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('credited_amount', 15, 2)->default(0);
            $table->decimal('recognised_tax_amount', 15, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_operation_id');
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn(['credited_amount', 'recognised_tax_amount', 'issued_at', 'sent_at']);
        });
    }
};
