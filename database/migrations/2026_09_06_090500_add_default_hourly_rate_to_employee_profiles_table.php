<?php

declare(strict_types=1);

use App\Services\Support\MaintenanceCostService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-09) — the labour rate source for
 * {@see MaintenanceCostService::recordLabour()} when no
 * rate is entered explicitly on the labour entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_hourly_rate_minor')->nullable()->after('commission_target_amount');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropColumn('default_hourly_rate_minor');
        });
    }
};
