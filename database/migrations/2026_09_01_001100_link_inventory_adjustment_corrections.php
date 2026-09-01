<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->foreignId('corrects_adjustment_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('inventory_adjustments')
                ->restrictOnDelete();

            $table->index(
                ['corrects_adjustment_id', 'status'],
                'inventory_adjustments_correction_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropIndex('inventory_adjustments_correction_status_index');
            $table->dropConstrainedForeignId('corrects_adjustment_id');
        });
    }
};
