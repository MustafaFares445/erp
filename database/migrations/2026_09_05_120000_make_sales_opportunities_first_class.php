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
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->string('origin', 30)->default('ai_voice_note');
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('estimated_value_minor')->nullable();
            $table->string('currency', 3)->default('AED');
            $table->date('expected_close_date')->nullable();
            $table->string('stage', 30)->default('qualification');
            $table->unsignedTinyInteger('probability_percent')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 40)->nullable();
            $table->text('close_note')->nullable();

            $table->index(['stage', 'expected_close_date']);
            $table->index(['owner_id', 'stage']);
            $table->index('customer_id');
            $table->index('lead_id');
        });

        Schema::create('opportunity_stage_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 30);
            $table->string('to_stage', 30);
            $table->foreignId('interaction_id')->nullable()->constrained('interactions')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['sales_opportunity_id', 'occurred_at']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('opportunity_title_snapshot')->nullable();
            $table->unsignedBigInteger('opportunity_estimated_value_minor_snapshot')->nullable();
        });

        DB::table('sales_opportunities')->where('status', 'draft')->update(['status' => 'Draft']);
        DB::table('sales_opportunities')->where('status', 'qualified')->update(['status' => 'Approved']);
        DB::table('sales_opportunities')->whereIn('status', ['closed_won', 'closed_lost'])->update(['status' => 'Approved']);
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['opportunity_title_snapshot', 'opportunity_estimated_value_minor_snapshot']);
        });
        Schema::dropIfExists('opportunity_stage_transitions');
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['lead_id']);
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['stage', 'expected_close_date']);
            $table->dropIndex(['owner_id', 'stage']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['lead_id']);
            $table->dropColumn([
                'origin', 'customer_id', 'lead_id', 'title', 'estimated_value_minor', 'currency',
                'expected_close_date', 'stage', 'probability_percent', 'owner_id', 'closed_at',
                'close_reason', 'close_note',
            ]);
        });
    }
};
