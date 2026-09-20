<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing active customers were implicitly already approved; existing
 * inactive customers await review, matching join-us's default. Pure data
 * migration (no schema change) so it can be re-run idempotently, mirroring
 * the other `*_backfill_*` migrations in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_profiles')->where('is_active', true)->update([
            'approval_status' => CustomerApprovalStatus::Approved->value,
        ]);

        DB::table('customer_profiles')->where('is_active', false)->update([
            'approval_status' => CustomerApprovalStatus::Pending->value,
        ]);
    }

    public function down(): void
    {
        // Data-only backfill; nothing to reverse.
    }
};
