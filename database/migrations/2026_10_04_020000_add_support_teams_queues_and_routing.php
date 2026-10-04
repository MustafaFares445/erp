<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_teams', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('assignment_strategy', 30)->default('manual');
            $table->unsignedInteger('default_capacity')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_skills', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('support_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('routing_weight')->default(100);
            $table->boolean('accepts_remote')->default(true);
            $table->boolean('accepts_onsite')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['support_team_id', 'employee_id']);
            $table->index(['support_team_id', 'is_active']);
            $table->index(['employee_id', 'is_active']);
        });

        Schema::create('support_employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->foreignId('support_skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('proficiency')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'support_skill_id']);
        });

        Schema::create('support_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('precedence')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('ticket_type', 30)->nullable();
            $table->string('service_path', 30)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_city')->nullable();
            $table->foreignId('support_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('required_skill_id')->nullable()->constrained('support_skills')->nullOnDelete();
            $table->boolean('auto_assign')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'precedence']);
        });

        Schema::create('support_queues', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('support_team_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(100);
            $table->json('criteria');
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('support_team_id')->nullable()->after('assigned_employee_id')->constrained()->nullOnDelete();
            $table->timestamp('routed_at')->nullable()->after('support_team_id');
            $table->foreignId('routed_by_rule_id')->nullable()->after('routed_at')->constrained('support_routing_rules')->nullOnDelete();
            $table->index(['support_team_id', 'status']);
            $table->index(['assigned_employee_id', 'status']);
        });

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->foreignId('support_team_id')->nullable()->after('service_path')->constrained()->nullOnDelete();
        });

        Schema::table('ticket_assignments', function (Blueprint $table): void {
            $table->string('assignment_source', 30)->default('manual')->after('assigned_at');
            $table->foreignId('support_team_id')->nullable()->after('assignment_source')->constrained()->nullOnDelete();
            $table->foreignId('routing_rule_id')->nullable()->after('support_team_id')->constrained('support_routing_rules')->nullOnDelete();
            $table->string('reason')->nullable()->after('routing_rule_id');
        });

        Schema::table('ticket_assignments', function (Blueprint $table): void {
            $table->foreignId('assigned_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Automatic (routing/automation) assignments legitimately have no human assigner. Attribute them to the
        // earliest user so the legacy NOT NULL contract can be restored; with no users at all they cannot exist.
        $fallbackUserId = DB::table('users')->orderBy('id')->value('id');
        $orphans = DB::table('ticket_assignments')->whereNull('assigned_by');
        $fallbackUserId === null ? $orphans->delete() : $orphans->update(['assigned_by' => $fallbackUserId]);

        Schema::table('ticket_assignments', function (Blueprint $table): void {
            $table->foreignId('assigned_by')->nullable(false)->change();
            $table->dropConstrainedForeignId('routing_rule_id');
            $table->dropConstrainedForeignId('support_team_id');
            $table->dropColumn(['assignment_source', 'reason']);
        });

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('support_team_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['routed_by_rule_id']);
            $table->dropForeign(['support_team_id']);
            $table->dropIndex(['support_team_id', 'status']);
            // The composite index is what backs the assigned_employee_id foreign key; give the key its own
            // index again before the composite one goes away.
            $table->index('assigned_employee_id');
            $table->dropIndex(['assigned_employee_id', 'status']);
            $table->dropColumn(['support_team_id', 'routed_at', 'routed_by_rule_id']);
        });

        Schema::dropIfExists('support_queues');
        Schema::dropIfExists('support_routing_rules');
        Schema::dropIfExists('support_employee_skills');
        Schema::dropIfExists('support_team_members');
        Schema::dropIfExists('support_skills');
        Schema::dropIfExists('support_teams');
    }
};
