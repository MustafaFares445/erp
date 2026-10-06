<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_replenishment_policies', function (Blueprint $table): void {
            $table->foreignId('preferred_supplier_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_replenishment_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_supplier_id');
        });
    }
};
