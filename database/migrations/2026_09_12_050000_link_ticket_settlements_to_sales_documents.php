<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_payment_links', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('payment_method_reference')->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('payment_method_id')->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->after('invoice_id')->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_payment_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
