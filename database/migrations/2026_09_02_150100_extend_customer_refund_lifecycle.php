<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('credit_note_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->after('status')->constrained()->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });

        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->foreignId('refund_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
            $table->index('refund_id');
        });
    }

    public function down(): void
    {
        Schema::table('tax_recognition_entries', function (Blueprint $table): void {
            $table->dropIndex(['refund_id']);
            $table->dropConstrainedForeignId('refund_id');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credit_note_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
    }
};
