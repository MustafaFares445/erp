<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->string('digest_cadence')->default('immediate')->after('enabled');
            $table->time('quiet_hours_start')->nullable()->after('digest_cadence');
            $table->time('quiet_hours_end')->nullable()->after('quiet_hours_start');
        });

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->unsignedInteger('rate_limit_per_hour')->nullable()->after('is_active');
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('decision')->nullable()->after('status')->index();
            $table->timestamp('deferred_until')->nullable()->after('queued_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['decision', 'deferred_until']);
        });

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropColumn('rate_limit_per_hour');
        });

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['digest_cadence', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};
