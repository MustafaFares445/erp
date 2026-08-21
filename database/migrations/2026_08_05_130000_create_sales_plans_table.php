<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('month');
            $table->date('active_month')->nullable();
            $table->decimal('task_weight', 5, 2);
            $table->decimal('visit_weight', 5, 2);
            $table->decimal('schedule_weight', 5, 2);
            $table->decimal('work_time_weight', 5, 2);
            $table->unsignedSmallInteger('required_visit_minutes')->nullable();
            $table->string('status', 30)->default('Draft');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->unique(['employee_id', 'active_month']);
            $table->index(['employee_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_plans');
    }
};
