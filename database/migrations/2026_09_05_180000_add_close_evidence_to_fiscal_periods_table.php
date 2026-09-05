<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WP-2.5 (GAP-MW-18): the close/reopen decision now carries its own
     * evidence — who closed it, when, and whether a failing mandatory
     * checklist item was overridden (and by whom) — rather than only the
     * generic `updated_by` blame trail every column already gets.
     * `close_override_reason` stays null unless a failing check was actually
     * overridden at close time.
     */
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->foreignId('closed_by')->nullable()->after('is_closed')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
            $table->text('close_override_reason')->nullable()->after('closed_at');
            $table->foreignId('close_override_by')->nullable()->after('close_override_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('close_override_by');
            $table->dropColumn('close_override_reason');
            $table->dropColumn('closed_at');
            $table->dropConstrainedForeignId('closed_by');
        });
    }
};
