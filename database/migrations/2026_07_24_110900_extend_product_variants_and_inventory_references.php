<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->text('description')->nullable()->after('name_ar');
            $table->string('status', 30)->default('active')->after('description')->index();
            $table->foreignId('category_id')->nullable()->after('status')->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('barcode', 100)->nullable()->unique()->after('name_ar');
            $table->foreignId('unit_id')->nullable()->after('barcode')->constrained('units')->nullOnDelete();
            $table->boolean('track_serials')->default(false)->after('unit_id');
            $table->boolean('track_expiry')->default(false)->after('track_serials');
            $table->decimal('cost_price', 15, 2)->nullable()->after('track_expiry');
            $table->decimal('base_price', 15, 2)->nullable()->after('cost_price');
            $table->decimal('min_price', 15, 2)->nullable()->after('base_price');
            $table->decimal('markup_percent', 5, 2)->nullable()->after('min_price');
            $table->string('status', 30)->default('active')->after('markup_percent')->index();
            $table->unique('sku');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('inventory_receipt_item_id')->nullable()->after('source_id')->constrained('inventory_receipt_items')->nullOnDelete();
            $table->foreignId('serialized_inventory_unit_id')->nullable()->after('inventory_receipt_item_id')->constrained('serialized_inventory_units')->nullOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->after('serialized_inventory_unit_id')->constrained('inventory_lots')->nullOnDelete();
        });

        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable()->after('status');
            $table->timestamp('received_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropColumn(['dispatched_at', 'received_at']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_receipt_item_id');
            $table->dropConstrainedForeignId('serialized_inventory_unit_id');
            $table->dropConstrainedForeignId('inventory_lot_id');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['sku']);
            $table->dropUnique(['barcode']);
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['name_ar', 'barcode', 'track_serials', 'track_expiry', 'cost_price', 'base_price', 'min_price', 'markup_percent', 'status']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['name_ar', 'description', 'status']);
        });
    }
};
