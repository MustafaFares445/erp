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
        Schema::create('support_service_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sla_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('timezone', 80)->default('UTC');
            $table->boolean('is_24x7')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sla_calendar_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_calendar_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->index(['sla_calendar_id', 'weekday']);
        });

        Schema::create('sla_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name')->nullable();
            $table->boolean('is_working_day')->default(false);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['sla_calendar_id', 'date']);
        });

        Schema::create('support_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('support_service_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('external_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['serialized_inventory_unit_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->dropUnique(['priority']);
            $table->string('name')->nullable()->after('id');
            $table->string('code', 80)->nullable()->unique()->after('name');
            $table->boolean('is_active')->default(true)->after('code')->index();
            $table->unsignedInteger('precedence')->default(100)->after('is_active')->index();
            $table->foreignId('sla_calendar_id')->nullable()->after('precedence')->constrained()->nullOnDelete();
            $table->string('ticket_type', 30)->nullable()->after('priority')->index();
            $table->string('service_path', 30)->nullable()->after('ticket_type')->index();
            $table->foreignId('support_service_level_id')->nullable()->after('service_path')->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('support_service_level_id')->constrained('customer_profiles')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->text('notes')->nullable()->after('resolution_target_minutes');
        });

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->string('priority', 20)->nullable()->change();
        });

        Schema::create('sla_policy_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_policy_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedInteger('target_minutes');
            $table->unsignedInteger('at_risk_before_minutes')->default(30);
            $table->boolean('pause_when_waiting_customer')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['sla_policy_id', 'key']);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('sla_policy_id')->nullable()->after('sla_resolution_target_minutes')->constrained()->nullOnDelete();
            $table->foreignId('support_entitlement_id')->nullable()->after('sla_policy_id')->constrained()->nullOnDelete();
        });

        Schema::create('ticket_sla_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 40);
            $table->unsignedInteger('target_minutes');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->timestamp('at_risk_notified_at')->nullable();
            $table->timestamp('breach_notified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['ticket_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_milestones');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('support_entitlement_id');
            $table->dropConstrainedForeignId('sla_policy_id');
        });

        Schema::dropIfExists('sla_policy_milestones');

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('support_service_level_id');
            $table->dropConstrainedForeignId('sla_calendar_id');
            $table->dropColumn(['name', 'code', 'is_active', 'precedence', 'ticket_type', 'service_path', 'notes']);
        });

        // Lossy by design: v2-only policies (customer/service-level/product rules, duplicate priorities)
        // cannot exist under the legacy one-row-per-priority contract, so only one row per priority survives.
        $keep = DB::table('sla_policies')->whereNotNull('priority')->selectRaw('MIN(id) as id')->groupBy('priority')->pluck('id');
        DB::table('sla_policies')->whereNotIn('id', $keep)->delete();

        Schema::table('sla_policies', function (Blueprint $table): void {
            $table->string('priority', 20)->nullable(false)->change();
            $table->unique('priority');
        });

        Schema::dropIfExists('support_entitlements');
        Schema::dropIfExists('sla_calendar_exceptions');
        Schema::dropIfExists('sla_calendar_periods');
        Schema::dropIfExists('sla_calendars');
        Schema::dropIfExists('support_service_levels');
    }
};
