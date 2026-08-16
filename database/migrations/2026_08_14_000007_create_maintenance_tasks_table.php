<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};
