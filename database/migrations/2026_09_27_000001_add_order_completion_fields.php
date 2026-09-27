<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer-completion / auto-close tracking columns to `orders`
 * (IERP_ORDER_CUSTOMER_CLOSE_PAYMENT_FLOW_IMPLEMENTATION_PLAN §6).
 *
 * `auto_close_days_snapshot` freezes the Sales Setting value at the moment
 * the completion window starts, so a later setting change never moves the
 * deadline of an already-started window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('closed_by_source', 20)->nullable()->after('closed_at');
            $table->unsignedInteger('auto_close_days_snapshot')->nullable()->after('closed_by_source');
            $table->timestamp('completion_window_started_at')->nullable()->after('auto_close_days_snapshot');
            $table->timestamp('auto_close_due_at')->nullable()->after('completion_window_started_at');

            $table->index(['status', 'auto_close_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['status', 'auto_close_due_at']);
            $table->dropColumn([
                'closed_by_source',
                'auto_close_days_snapshot',
                'completion_window_started_at',
                'auto_close_due_at',
            ]);
        });
    }
};
