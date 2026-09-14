<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('due_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->text('work_performed')->nullable()->after('completed_at');
            $table->text('completion_notes')->nullable()->after('work_performed');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table): void {
            $table->dropColumn(['started_at', 'completed_at', 'work_performed', 'completion_notes']);
        });
    }
};
