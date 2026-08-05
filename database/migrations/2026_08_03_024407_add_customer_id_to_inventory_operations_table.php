<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('customer_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operations', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
