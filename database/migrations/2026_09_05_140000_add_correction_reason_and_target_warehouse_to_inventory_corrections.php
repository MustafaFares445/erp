<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.11 (GAP-BW-02): lets a correction carry a controlled reason (reusing WP-1.1's
 * {@see ConditionChangeReason} list) and, for a transfer whose destination was wrong,
 * the warehouse the stock should have gone to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_corrections', function (Blueprint $table): void {
            $table->string('correction_reason', 40)->nullable()->after('correction_type');
            $table->foreignId('target_warehouse_id')
                ->nullable()
                ->after('correction_reason')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_corrections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_warehouse_id');
            $table->dropColumn('correction_reason');
        });
    }
};
