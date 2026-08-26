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
        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('payment_amount', 15, 2)->nullable();
            $table->decimal('recognised_tax_amount', 15, 2)->nullable();
            $table->date('recognition_date')->nullable()->index();
            $table->unique(['invoice_id', 'payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->dropUnique(['invoice_id', 'payment_id']);
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['payment_amount', 'recognised_tax_amount', 'recognition_date']);
        });
    }
};
