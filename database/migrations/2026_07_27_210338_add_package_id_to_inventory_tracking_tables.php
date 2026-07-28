<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_adjustment_items', 'stock_transfer_items', 'inventory_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropForeign(['package_id']);
        });

        foreach (['inventory_adjustment_items', 'stock_transfer_items', 'inventory_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('package_id');
            });
        }
    }
};
