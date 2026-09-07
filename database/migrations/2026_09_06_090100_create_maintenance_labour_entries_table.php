<?php

declare(strict_types=1);

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.9 (GAP-MW-09) — labour time spent on a job, at a rate, so it can be
 * costed and (WP-2.9's billing half, GAP-MW-10) billed as a service line.
 * Scoped to the {@see MaintenanceRecord} rather than only its
 * {@see MaintenanceTask} because labour is often logged against
 * the job as a whole; `service_record_id` narrows it to one service record
 * when known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_labour_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->cascadeOnDelete();
            $table->foreignId('service_record_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->date('performed_on');
            $table->unsignedInteger('minutes');
            $table->unsignedBigInteger('hourly_rate_minor');
            $table->unsignedBigInteger('total_cost_minor');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['maintenance_record_id', 'performed_on'], 'maintenance_labour_entries_record_performed_on_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_labour_entries');
    }
};
