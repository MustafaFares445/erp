<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 40);
            $table->string('invariant', 60);
            $table->boolean('passed');
            $table->unsignedInteger('divergence_count')->default(0);
            $table->json('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_source', 20);
            $table->timestamps();

            $table->index(['scope', 'invariant', 'finished_at'], 'reconciliation_scope_invariant_finished_idx');
            $table->index(['scope', 'passed', 'finished_at'], 'reconciliation_scope_passed_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
