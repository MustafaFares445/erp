<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'tickets_status_created_idx');
            $table->index('resolved_at', 'tickets_resolved_at_idx');
            $table->index(['status', 'waiting_customer_since'], 'tickets_status_waiting_customer_idx');
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->index(
                ['ticket_id', 'is_internal_note', 'id'],
                'ticket_messages_public_conversation_idx',
            );
        });

        Schema::table('ticket_sla_milestones', function (Blueprint $table): void {
            $table->index(
                ['completed_at', 'breached_at'],
                'ticket_sla_completed_breached_idx',
            );
        });

        Schema::table('ticket_satisfaction_responses', function (Blueprint $table): void {
            $table->index('submitted_at', 'ticket_satisfaction_submitted_idx');
        });

        Schema::table('warranty_recovery_claims', function (Blueprint $table): void {
            $table->index('created_at', 'warranty_recovery_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_recovery_claims', function (Blueprint $table): void {
            $table->dropIndex('warranty_recovery_created_idx');
        });

        Schema::table('ticket_satisfaction_responses', function (Blueprint $table): void {
            $table->dropIndex('ticket_satisfaction_submitted_idx');
        });

        Schema::table('ticket_sla_milestones', function (Blueprint $table): void {
            $table->dropIndex('ticket_sla_completed_breached_idx');
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropIndex('ticket_messages_public_conversation_idx');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_status_created_idx');
            $table->dropIndex('tickets_resolved_at_idx');
            $table->dropIndex('tickets_status_waiting_customer_idx');
        });
    }
};
