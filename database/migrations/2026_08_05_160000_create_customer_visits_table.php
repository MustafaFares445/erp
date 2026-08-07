<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->foreignId('plan_task_id')->nullable()->constrained('plan_tasks')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->string('recorded_channel', 20)->default('Dashboard');
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->text('outcome')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('status', 20)->default('Planned');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->index(['plan_task_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
    }
};
