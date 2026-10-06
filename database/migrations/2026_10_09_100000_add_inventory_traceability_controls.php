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
        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('expiry_critical_days')->default(30)->after('expiry_alert_days');
            $table->unsignedSmallInteger('expiry_warning_days')->default(60)->after('expiry_critical_days');
            $table->unsignedSmallInteger('expiry_notice_days')->default(90)->after('expiry_warning_days');
        });

        DB::table('inventory_settings')
            ->orderBy('id')
            ->get(['id', 'expiry_alert_days'])
            ->each(function (object $setting): void {
                $legacy = is_numeric($setting->expiry_alert_days)
                    ? max(1, (int) $setting->expiry_alert_days)
                    : 30;

                DB::table('inventory_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'expiry_critical_days' => min($legacy, 30),
                        'expiry_warning_days' => max($legacy, 60),
                        'expiry_notice_days' => max($legacy, 90),
                    ]);
            });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->string('fefo_override_reason', 255)->nullable()->after('inventory_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropColumn('fefo_override_reason');
        });

        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'expiry_critical_days',
                'expiry_warning_days',
                'expiry_notice_days',
            ]);
        });
    }
};
