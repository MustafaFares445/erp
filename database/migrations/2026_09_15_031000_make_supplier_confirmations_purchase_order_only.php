<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();
        });

        DB::table('supplier_confirmations')
            ->where('confirmable_type', PurchaseOrder::class)
            ->update(['purchase_order_id' => DB::raw('confirmable_id')]);

        DB::table('supplier_confirmations')
            ->whereNull('purchase_order_id')
            ->delete();

        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->dropIndex('supplier_confirmations_customer_id_index');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropMorphs('confirmable');
            $table->foreignId('purchase_order_id')->nullable(false)->change();
            $table->index(['purchase_order_id', 'created_at'], 'supplier_confirmations_po_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->nullableMorphs('confirmable');
            $table->foreignId('customer_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('customer_profiles')
                ->nullOnDelete();
        });

        DB::table('supplier_confirmations')->update([
            'confirmable_type' => PurchaseOrder::class,
            'confirmable_id' => DB::raw('purchase_order_id'),
        ]);

        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->dropIndex('supplier_confirmations_po_created_index');
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
