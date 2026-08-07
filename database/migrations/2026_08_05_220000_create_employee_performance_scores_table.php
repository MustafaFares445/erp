<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_performance_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->decimal('task_score', 5, 2);
            $table->decimal('visit_score', 5, 2);
            $table->decimal('schedule_score', 5, 2);
            $table->decimal('work_time_score', 5, 2);
            $table->decimal('total_score', 5, 2);
            $table->decimal('task_completion_percent', 5, 2);
            $table->json('calculation_breakdown');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['sales_plan_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_performance_scores');
    }
};
