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
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory_operations DROP CHECK inventory_operations_delivery_tracking_number_not_null');
        }

        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->dropUnique('inventory_operations_tracking_number_unique');
            $table->dropIndex('inventory_operations_status_changed_by_index');
            $table->dropIndex('inventory_operations_status_changed_at_index');
            $table->dropColumn([
                'tracking_number',
                'status_changed_by_type',
                'status_changed_by_id',
                'status_changed_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tracking_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('tracking_reference', 100)->nullable();
        });

        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->string('tracking_number', 100)->nullable()->unique();
            $table->string('status_changed_by_type', 30)->nullable();
            $table->unsignedBigInteger('status_changed_by_id')->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });
    }
};
