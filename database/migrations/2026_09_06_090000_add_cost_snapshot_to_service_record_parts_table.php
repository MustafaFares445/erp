<?php

declare(strict_types=1);

use App\Services\Purchasing\SupplierCostWritebackService;
use App\Services\Support\ServiceRecordPartService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-09) — snapshots the part's unit cost at consumption time so
 * job-cost and service-margin reporting has a figure to read. `unit_cost_minor`
 * and `total_cost_minor` are copied from {@see SupplierCostWritebackService}'s
 * last-received cost at the moment {@see ServiceRecordPartService::consume()}
 * runs — a snapshot, not a live lookup, mirroring price provenance elsewhere in
 * this codebase (WP-2.3). `cost_source` records where the figure came from, or
 * that none exists (`unknown`), so coverage can be reported honestly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_record_parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_cost_minor')->nullable()->after('quantity');
            $table->unsignedBigInteger('total_cost_minor')->nullable()->after('unit_cost_minor');
            $table->string('cost_source', 30)->nullable()->after('total_cost_minor');
        });
    }

    public function down(): void
    {
        Schema::table('service_record_parts', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost_minor', 'total_cost_minor', 'cost_source']);
        });
    }
};
