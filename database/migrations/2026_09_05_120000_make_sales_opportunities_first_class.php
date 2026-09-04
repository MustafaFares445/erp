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
            $table->foreignId('customer_profile_id')->nullable()->after('ai_keyword_rule_id')->constrained('customer_profiles')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('customer_profile_id')->constrained('leads')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('lead_id')->constrained('users')->nullOnDelete();
            $table->decimal('estimated_value', 15, 2)->nullable()->after('summary');
            $table->date('expected_close_date')->nullable()->after('estimated_value');
            $table->string('review_status', 20)->default('not_required')->after('status');
            $table->string('close_reason', 255)->nullable()->after('review_notes');
            $table->timestamp('closed_at')->nullable()->after('close_reason');

            $table->index(['owner_id', 'status']);
            $table->index(['status', 'expected_close_date']);
            $table->index('review_status');
        });

        DB::table('sales_opportunities')
            ->whereIn('status', ['Draft', 'draft'])
            ->update(['status' => 'draft']);
        DB::table('sales_opportunities')
            ->whereIn('status', ['Qualified', 'qualified'])
            ->update(['status' => 'qualified']);
        DB::table('sales_opportunities')
            ->whereIn('status', ['ClosedWon', 'closed_won'])
            ->update(['status' => 'closed_won']);
        DB::table('sales_opportunities')
            ->whereIn('status', ['ClosedLost', 'closed_lost'])
            ->update(['status' => 'closed_lost']);

        DB::table('sales_opportunities')
            ->where(function ($query): void {
                $query->whereNotNull('voice_note_transcription_id')
                    ->orWhereNotNull('ai_keyword_rule_id');
            })
            ->whereNull('reviewed_at')
            ->update(['review_status' => 'pending']);

        DB::table('sales_opportunities')
            ->whereIn('status', ['Approved', 'approved'])
            ->update(['review_status' => 'approved', 'status' => 'qualified']);

        DB::table('sales_opportunities')
            ->whereIn('status', ['Rejected', 'rejected'])
            ->update([
                'review_status' => 'rejected',
                'status' => 'closed_lost',
                'close_reason' => 'AI review rejected',
                'closed_at' => DB::raw('COALESCE(reviewed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['customer_profile_id']);
            $table->dropForeign(['lead_id']);
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id', 'status']);
            $table->dropIndex(['status', 'expected_close_date']);
            $table->dropIndex(['review_status']);
            $table->dropColumn([
                'customer_profile_id',
                'lead_id',
                'owner_id',
                'estimated_value',
                'expected_close_date',
                'review_status',
                'close_reason',
                'closed_at',
            ]);
        });
    }
};
