<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code', 50);
            $table->string('job_title', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('use_base_salary')->default(true);
            $table->decimal('base_salary', 15, 2)->nullable();
            $table->decimal('commission_target_amount', 15, 2)->nullable();
            $table->string('salary_calculation_mode', 30);
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->unique('user_id');
            $table->unique('employee_code');
            $table->index('is_active');
            $table->index('job_title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
