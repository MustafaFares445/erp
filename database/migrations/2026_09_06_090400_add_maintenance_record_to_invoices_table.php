<?php

declare(strict_types=1);

use App\Services\Sales\InvoiceService;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-10) — traces a chargeable-service invoice back to the job
 * that earned it. Populated only by {@see MaintenanceBillingService::createInvoice()},
 * which delegates the invoice itself to {@see InvoiceService::createStandalone()}
 * so it follows the exact same issue/collect/tax path as a goods invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // constrained() already indexes the referencing column for the foreign key.
            $table->foreignId('maintenance_record_id')->nullable()->after('order_id')->constrained('maintenance_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('maintenance_record_id');
        });
    }
};
