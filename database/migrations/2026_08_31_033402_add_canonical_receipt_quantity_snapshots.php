<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->decimal('transaction_quantity', 20, 6)->nullable()->after('quantity');
            $table->foreignId('transaction_unit_id')->nullable()->after('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6)->nullable()->after('transaction_unit_id');
            $table->decimal('base_quantity', 20, 6)->nullable()->after('conversion_factor_snapshot');
            $table->foreignId('purchase_order_line_id')->nullable()->after('inventory_operation_id')->constrained()->restrictOnDelete();

            $table->index('purchase_order_line_id');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('transaction_quantity', 20, 6)->nullable()->after('quantity_ordered');
            $table->foreignId('transaction_unit_id')->nullable()->after('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6)->nullable()->after('transaction_unit_id');
            $table->decimal('base_quantity', 20, 6)->nullable()->after('conversion_factor_snapshot');
            $table->decimal('received_base_quantity', 20, 6)->nullable()->after('quantity_received');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('source_line_type', 100)->nullable()->after('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable()->after('source_line_type');
            $table->decimal('transaction_quantity', 20, 6)->nullable()->after('quantity');
            $table->foreignId('transaction_unit_id')->nullable()->after('transaction_quantity')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6)->nullable()->after('transaction_unit_id');
            $table->decimal('base_quantity_delta', 20, 6)->nullable()->after('conversion_factor_snapshot');

            $table->index(['source_line_type', 'source_line_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex(['source_line_type', 'source_line_id']);
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn([
                'source_line_type',
                'source_line_id',
                'transaction_quantity',
                'conversion_factor_snapshot',
                'base_quantity_delta',
            ]);
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn([
                'transaction_quantity',
                'conversion_factor_snapshot',
                'base_quantity',
                'received_base_quantity',
            ]);
        });

        Schema::table('inventory_operation_lines', function (Blueprint $table): void {
            $table->dropIndex(['purchase_order_line_id']);
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn([
                'transaction_quantity',
                'conversion_factor_snapshot',
                'base_quantity',
            ]);
        });
    }
};
