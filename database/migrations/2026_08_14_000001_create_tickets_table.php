<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 100)->unique();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->string('type', 30);
            $table->string('priority', 20);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 30);
            $table->string('pending_reason', 100)->nullable();
            $table->boolean('is_chargeable')->default(false);
            $table->foreignId('continued_from_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();

            // SLA snapshot and clock (ADR 0004 ext. 1) — dormant until spec
            // 016's User Story 5 populates them; created here because every
            // migration in this codebase creates a table's full column set in
            // the earliest story that owns it (matching how spec 015's
            // employee_profiles.commission_target_amount, used only by a
            // later salary story, was created with the base migration).
            $table->unsignedInteger('sla_response_target_minutes')->nullable();
            $table->unsignedInteger('sla_resolution_target_minutes')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->timestamp('waiting_customer_since')->nullable();
            $table->unsignedInteger('waiting_customer_accumulated_seconds')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index(['response_due_at', 'response_breached']);
            $table->index(['resolution_due_at', 'resolution_breached']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
