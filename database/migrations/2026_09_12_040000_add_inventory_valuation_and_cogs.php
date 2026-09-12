<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->foreignId('inventory_asset_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->foreignId('shrinkage_expense_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
        });

        Schema::table('purchase_settings', function (Blueprint $table): void {
            $table->foreignId('grni_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
        });

        Schema::create('inventory_valuation_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_base', 18, 6)->default(0);
            $table->decimal('average_unit_cost', 18, 6)->default(0);
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_id'], 'inventory_valuation_balance_unique');
        });

        Schema::create('inventory_valuation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_movement_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->date('valuation_date');
            $table->string('valuation_method', 32)->default('weighted_average');
            $table->decimal('base_quantity_delta', 18, 6);
            $table->decimal('unit_cost_snapshot', 18, 6);
            $table->decimal('inventory_value_delta', 18, 2);
            $table->timestamps();

            $table->index(['valuation_date', 'product_variant_id'], 'inventory_valuation_entries_date_variant_index');
            $table->index(['warehouse_id', 'valuation_date'], 'inventory_valuation_entries_warehouse_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_valuation_entries');
        Schema::dropIfExists('inventory_valuation_balances');

        Schema::table('purchase_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('grni_account_id');
        });

        Schema::table('inventory_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_asset_account_id');
            $table->dropConstrainedForeignId('cogs_account_id');
            $table->dropConstrainedForeignId('shrinkage_expense_account_id');
        });
    }
};
