<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton settings row for the Sales module, following the
 * `inventory_settings` / `purchase_settings` precedent (data-model.md §1).
 *
 * Not in the ERD — ADR 0008 (D7) authorises it as the replacement for a
 * `tax_definitions` rate catalogue, and (E-7) as the home for the four
 * accounts the sales lifecycle posts to.
 *
 * The five account references are nullable at rest, so a fresh install can
 * run migrations and open the page before any account is configured; each
 * posting service refuses to post through a null, non-postable, or inactive
 * account and names it in the error (FR-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('default_tax_percent', 5, 2)->default(0);
            $table->unsignedInteger('default_quotation_validity_days')->default(30);
            $table->foreignId('receivable_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('deferred_tax_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('tax_payable_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('customer_deposits_account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_settings');
    }
};
