<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedule_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_schedule_id')->constrained('maintenance_schedules')->cascadeOnDelete();
            $table->date('due_on');
            $table->string('status', 20)->default('pending');
            $table->foreignId('maintenance_record_id')->nullable()->constrained('maintenance_records')->nullOnDelete();
            $table->timestamp('raised_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('skipped_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['maintenance_schedule_id', 'due_on'], 'maintenance_schedule_occurrences_schedule_due_on_unique');
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedule_occurrences');
    }
};
