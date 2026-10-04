<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('event_key', 80);
            $table->unsignedInteger('precedence')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('stop_processing')->default(false);
            $table->json('conditions');
            $table->json('actions');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_key', 'is_active', 'precedence']);
        });

        Schema::create('support_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_automation_rule_id')->constrained()->cascadeOnDelete();
            $table->string('event_uuid', 64);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 30);
            $table->boolean('matched')->default(false);
            $table->json('actions_executed')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['support_automation_rule_id', 'event_uuid'], 'support_automation_rule_event_unique');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'support_automation_subject_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_automation_runs');
        Schema::dropIfExists('support_automation_rules');
    }
};
