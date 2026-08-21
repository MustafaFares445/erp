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
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->string('status_changed_by_type', 30)->nullable()->after('delivery_type');
            $table->unsignedBigInteger('status_changed_by_id')->nullable()->after('status_changed_by_type');
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by_id');
            $table->index(['status_changed_by_type', 'status_changed_by_id'], 'inventory_operations_status_changed_by_index');
            $table->index('status_changed_at');
        });

        DB::table('inventory_operations')
            ->whereNull('status_changed_by_type')
            ->update([
                'status_changed_by_type' => 'system',
                'status_changed_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->dropIndex('inventory_operations_status_changed_by_index');
            $table->dropIndex(['status_changed_at']);
            $table->dropColumn(['status_changed_by_type', 'status_changed_by_id', 'status_changed_at']);
        });
    }
};
