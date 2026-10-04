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
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->string('maintenance_kind', 30)->default('other')->after('status')->index();
            $table->timestamp('failure_started_at')->nullable()->after('maintenance_kind')->index();
            $table->timestamp('service_restored_at')->nullable()->after('failure_started_at')->index();
        });

        DB::table('maintenance_records')
            ->whereNotNull('ticket_id')
            ->update(['maintenance_kind' => 'corrective']);

        DB::table('maintenance_records')
            ->whereIn('id', DB::table('maintenance_schedule_occurrences')->select('maintenance_record_id')->whereNotNull('maintenance_record_id'))
            ->update(['maintenance_kind' => 'preventive']);
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_kind', 'failure_started_at', 'service_restored_at']);
        });
    }
};
