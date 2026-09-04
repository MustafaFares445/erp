<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(
                ['customer_id', 'status', 'due_date'],
                'invoices_customer_status_due_date_index',
            );
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->index('invoice_id', 'payment_allocations_invoice_id_ar_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropIndex('payment_allocations_invoice_id_ar_index');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_customer_status_due_date_index');
        });
    }
};