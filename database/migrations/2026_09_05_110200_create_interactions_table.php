<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->string('outcome', 30)->nullable();
            $table->timestamp('occurred_at');
            $table->string('summary', 255);
            $table->text('notes')->nullable();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_visit_id')->nullable()->constrained('customer_visits')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'interactions_subject_occurred_index');
            $table->index(['employee_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
