<?php

declare(strict_types=1);

use App\Services\Crm\CustomerTimelineService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-3.1 (GAP-UI-03) — covering indexes for {@see CustomerTimelineService}'s
 * paginated union: `tickets` and `customer_visits` are the two source tables with no index
 * already suited to "this customer, most recent first". Every other timeline source already has
 * one (customer_id is indexed via its foreign key, and the date columns used for ordering are
 * already indexed alongside it elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at']);
        });

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->index(['customer_id', 'planned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'created_at']);
        });

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'planned_at']);
        });
    }
};
