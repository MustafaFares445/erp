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
        Schema::table('sales_plans', function (Blueprint $table): void {
            $table->decimal('opportunity_weight', 5, 2)->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('sales_plans')->whereIn('status', ['Active', 'Paused'])->update(['status' => 'InProgress']);

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->string('reference', 50)->nullable()->unique();
            $table->string('visit_type', 80)->nullable();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('en_route_at')->nullable();
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->timestamp('check_in_recorded_at')->nullable();
            $table->decimal('check_in_accuracy_meters', 10, 2)->nullable();
            $table->unsignedInteger('distance_from_customer_meters')->nullable();
            $table->boolean('location_warning')->default(false);
            $table->text('location_override_reason')->nullable();
            $table->foreignId('location_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('location_overridden_at')->nullable();
            $table->text('schedule_override_reason')->nullable();
            $table->foreignId('schedule_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('schedule_overridden_at')->nullable();
            $table->string('outcome_code', 50)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->text('employee_notes')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_note')->nullable();

            $table->index(['employee_id', 'scheduled_start_at', 'scheduled_end_at'], 'customer_visits_employee_schedule_index');
            $table->index(['status', 'scheduled_start_at'], 'customer_visits_status_schedule_index');
            $table->index(['location_warning', 'follow_up_required'], 'customer_visits_warning_followup_index');
        });

        DB::table('customer_visits')
            ->whereNull('scheduled_start_at')
            ->whereNotNull('planned_at')
            ->update(['scheduled_start_at' => DB::raw('planned_at')]);

        DB::table('customer_visits')
            ->whereIn('status', ['Planned', 'planned'])
            ->update(['status' => 'scheduled']);

        DB::table('customer_visits')
            ->whereIn('status', ['InProgress', 'in_progress'])
            ->update(['status' => 'in_progress']);

        DB::table('customer_visits')
            ->whereIn('status', ['Completed', 'completed'])
            ->update(['status' => 'completed']);

        DB::table('customer_visits')
            ->whereIn('status', ['Missed', 'missed'])
            ->update(['status' => 'unable_to_complete']);

        DB::table('customer_visits')
            ->whereNotNull('outcome')
            ->whereNull('outcome_notes')
            ->update([
                'outcome_code' => 'other',
                'outcome_notes' => DB::raw('outcome'),
            ]);

        Schema::table('plan_tasks', function (Blueprint $table): void {
            $table->foreignId('source_visit_id')->nullable()->unique()->constrained('customer_visits')->nullOnDelete();
        });

        Schema::table('employee_performance_scores', function (Blueprint $table): void {
            $table->dropUnique(['sales_plan_id', 'employee_id']);
            $table->decimal('opportunity_score', 5, 2)->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('factor_weights')->nullable();
            $table->json('raw_values')->nullable();
            $table->json('weighted_values')->nullable();
            $table->index(['sales_plan_id', 'employee_id'], 'employee_performance_scores_plan_employee_index');
        });

        Schema::table('employee_salary_calculations', function (Blueprint $table): void {
            $table->foreignId('performance_score_id')->nullable()->constrained('employee_performance_scores')->nullOnDelete();
            $table->boolean('use_base_salary_snapshot')->nullable();
            $table->decimal('base_salary_snapshot', 15, 2)->nullable();
            $table->string('salary_calculation_mode_snapshot', 80)->nullable();
            $table->json('calculation_explanation')->nullable();
        });

        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->foreignId('source_visit_id')->nullable()->constrained('customer_visits')->nullOnDelete();
            $table->foreignId('source_voice_note_id')->nullable()->constrained('employee_voice_notes')->nullOnDelete();
            $table->foreignId('detected_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('detected_product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('transcript_excerpt')->nullable();
            $table->json('detection_metadata')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->index(['source_visit_id', 'status'], 'sales_opportunities_visit_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropIndex('sales_opportunities_visit_status_index');
            $table->dropConstrainedForeignId('source_visit_id');
            $table->dropConstrainedForeignId('source_voice_note_id');
            $table->dropConstrainedForeignId('detected_product_id');
            $table->dropConstrainedForeignId('detected_product_variant_id');
            $table->dropColumn(['transcript_excerpt', 'detection_metadata', 'rejection_reason']);
        });

        Schema::table('employee_salary_calculations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('performance_score_id');
            $table->dropColumn([
                'use_base_salary_snapshot',
                'base_salary_snapshot',
                'salary_calculation_mode_snapshot',
                'calculation_explanation',
            ]);
        });

        Schema::table('employee_performance_scores', function (Blueprint $table): void {
            $table->dropIndex('employee_performance_scores_plan_employee_index');
            $table->dropColumn([
                'opportunity_score',
                'period_start',
                'period_end',
                'factor_weights',
                'raw_values',
                'weighted_values',
            ]);
        });

        Schema::table('plan_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_visit_id');
        });

        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_overridden_by');
            $table->dropConstrainedForeignId('schedule_overridden_by');
            $table->dropIndex('customer_visits_employee_schedule_index');
            $table->dropIndex('customer_visits_status_schedule_index');
            $table->dropIndex('customer_visits_warning_followup_index');
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'reference',
                'visit_type',
                'scheduled_start_at',
                'scheduled_end_at',
                'en_route_at',
                'check_in_latitude',
                'check_in_longitude',
                'check_in_recorded_at',
                'check_in_accuracy_meters',
                'distance_from_customer_meters',
                'location_warning',
                'location_override_reason',
                'location_overridden_at',
                'schedule_override_reason',
                'schedule_overridden_at',
                'outcome_code',
                'outcome_notes',
                'employee_notes',
                'follow_up_required',
                'follow_up_date',
                'follow_up_note',
            ]);
        });

        DB::table('sales_plans')->where('status', 'InProgress')->update(['status' => 'Active']);

        Schema::table('sales_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['opportunity_weight', 'published_at']);
        });
    }
};
