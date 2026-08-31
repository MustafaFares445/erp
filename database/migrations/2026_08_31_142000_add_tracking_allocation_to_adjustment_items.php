<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->foreignId('inventory_lot_id')
                ->nullable()
                ->after('serialized_inventory_unit_id')
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->decimal('old_quantity', 20, 6)->default(0)->change();
            $table->decimal('new_quantity', 20, 6)->change();
            $table->decimal('difference', 20, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_lot_id');
            $table->decimal('old_quantity', 15, 3)->default(0)->change();
            $table->decimal('new_quantity', 15, 3)->change();
            $table->decimal('difference', 15, 3)->default(0)->change();
        });
    }
};
