<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('default_currency_code', 3)->nullable()->after('address')->index();
            $table->foreignId('payment_term_id')->nullable()->after('default_currency_code')->constrained('payment_terms')->nullOnDelete();
            $table->unsignedSmallInteger('default_lead_time_days')->nullable()->after('payment_term_id');
            $table->text('notes')->nullable()->after('default_lead_time_days');
        });

        Schema::table('supplier_product_references', function (Blueprint $table): void {
            $table->foreignId('purchase_unit_id')->nullable()->after('product_variant_id')->constrained('units')->nullOnDelete();
            $table->decimal('pack_size', 20, 6)->nullable()->after('purchase_unit_id');
            $table->date('valid_from')->nullable()->after('minimum_order_quantity')->index();
            $table->date('valid_to')->nullable()->after('valid_from')->index();
        });
        $this->repairSqliteActiveSupplierReferenceIndex();

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('payment_term_id')->nullable()->after('currency_code')->constrained('payment_terms')->nullOnDelete();
        });

        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->string('supplier_reference', 150)->nullable()->after('purchase_order_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_confirmations', function (Blueprint $table): void {
            $table->dropIndex(['supplier_reference']);
            $table->dropColumn('supplier_reference');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_term_id');
        });

        Schema::table('supplier_product_references', function (Blueprint $table): void {
            $table->dropIndex(['valid_from']);
            $table->dropIndex(['valid_to']);
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropColumn(['pack_size', 'valid_from', 'valid_to']);
        });
        $this->repairSqliteActiveSupplierReferenceIndex();

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['default_currency_code']);
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn(['default_currency_code', 'default_lead_time_days', 'notes']);
        });
    }

    private function repairSqliteActiveSupplierReferenceIndex(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS supplier_reference_active_variant_unique');
        DB::statement(
            'CREATE UNIQUE INDEX supplier_reference_active_variant_unique '
            .'ON supplier_product_references (supplier_id, product_variant_id) '
            .'WHERE is_active = 1 AND deleted_at IS NULL',
        );
    }
};
