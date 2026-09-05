<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WP-2.5 (GAP-MW-18): the reconciliation pack a close (or reopen)
     * decision rests on, retained as evidence rather than recomputed on
     * demand — an auditor asks for what was actually checked at that moment,
     * not today's figures (AC-10).
     */
    public function up(): void
    {
        Schema::create('fiscal_period_close_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_period_id')->constrained()->cascadeOnDelete();
            $table->string('check_key', 60);
            $table->boolean('passed');
            $table->json('detail')->nullable();
            $table->timestamp('measured_at');
            $table->foreignId('reconciliation_run_id')->nullable()->constrained('reconciliation_runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['fiscal_period_id', 'check_key', 'measured_at'], 'fiscal_period_close_checks_period_check_measured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_period_close_checks');
    }
};
