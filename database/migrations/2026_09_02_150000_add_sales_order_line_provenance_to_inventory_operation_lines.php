<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->foreignId('order_line_id')->nullable()->after('purchase_order_line_id')
                ->constrained('order_lines')->restrictOnDelete();
            $table->index('order_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropIndex(['order_line_id']);
            $table->dropConstrainedForeignId('order_line_id');
        });
    }
};
