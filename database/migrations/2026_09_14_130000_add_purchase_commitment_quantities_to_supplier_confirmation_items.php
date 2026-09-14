<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_confirmation_items', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('purchase_order_lines')
                ->restrictOnDelete();
            $table->decimal('requested_base_quantity', 20, 6)->nullable()->after('requested_quantity');
            $table->decimal('confirmed_base_quantity', 20, 6)->nullable()->after('requested_base_quantity');
            $table->decimal('backordered_base_quantity', 20, 6)->nullable()->after('confirmed_base_quantity');

            $table->unique(
                ['supplier_confirmation_id', 'purchase_order_line_id'],
                'supplier_confirmation_po_line_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('supplier_confirmation_items', function (Blueprint $table): void {
            $table->dropUnique('supplier_confirmation_po_line_unique');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropColumn([
                'requested_base_quantity',
                'confirmed_base_quantity',
                'backordered_base_quantity',
            ]);
        });
    }
};
