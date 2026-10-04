<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table): void {
            $table->string('maintenance_kind', 30)->default('preventive')->after('name');
            $table->index(['maintenance_kind', 'is_active', 'next_due_on'], 'maintenance_schedules_kind_active_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table): void {
            $table->dropIndex('maintenance_schedules_kind_active_due_index');
            $table->dropColumn('maintenance_kind');
        });
    }
};
