<?php

declare(strict_types=1);

use App\Services\Support\MaintenanceBillingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-10) — links a job to how (and whether) it was billed. A
 * `Closed` job may be billed at most once: `quotation_id`/`invoice_id` are
 * set together with `billed_at` by {@see MaintenanceBillingService},
 * never independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->string('billing_type', 30)->default('unbilled')->after('status');
            $table->foreignId('quotation_id')->nullable()->after('billing_type')->constrained('quotations')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('quotation_id')->constrained('invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable()->after('invoice_id');

            // The work package calls for an index on (billing_type, closed_at), but
            // MaintenanceRecord has no closed_at column — status carries that state
            // (MaintenanceStatus::Closed) — so the index is built on (billing_type, status),
            // which serves the same "closed and unbilled" query shape.
            $table->index(['billing_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropIndex(['billing_type', 'status']);
            $table->dropConstrainedForeignId('quotation_id');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['billing_type', 'billed_at']);
        });
    }
};
