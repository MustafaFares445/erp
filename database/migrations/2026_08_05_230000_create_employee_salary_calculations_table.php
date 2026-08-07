<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_calculations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->decimal('payable_base', 15, 2);
            $table->decimal('performance_percent', 5, 2);
            $table->decimal('bonus_amount', 15, 2);
            $table->decimal('final_salary', 15, 2);
            $table->string('status', 30)->default('Draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('employee_salary_calculations')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['sales_plan_id', 'employee_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_calculations');
    }
};
