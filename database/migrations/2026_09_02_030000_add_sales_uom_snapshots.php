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
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 20, 6)->change();
            $table->foreignId('unit_id')->nullable()->after('product_variant_id')->constrained('units')->restrictOnDelete();
            $table->decimal('transaction_quantity', 20, 6)->nullable()->after('quantity');
            $table->foreignId('transaction_unit_id')->nullable()->after('transaction_quantity')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6)->nullable()->after('transaction_unit_id');
            $table->decimal('base_quantity', 20, 6)->nullable()->after('conversion_factor_snapshot');
        });

        // MySQL may use the old composite unique index as the supporting
        // index for the order_id foreign key. Materialize an explicit support
        // index before replacing that uniqueness contract.
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->index('order_id', 'order_lines_order_id_support_index');
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'product_variant_id']);
            $table->decimal('quantity', 20, 6)->change();
            $table->decimal('transaction_quantity', 20, 6)->nullable()->after('quantity');
            $table->foreignId('transaction_unit_id')->nullable()->after('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor_snapshot', 20, 6)->nullable()->after('transaction_unit_id');
            $table->decimal('base_quantity', 20, 6)->nullable()->after('conversion_factor_snapshot');
            $table->unique(['order_id', 'product_variant_id', 'unit_id'], 'order_lines_order_variant_unit_unique');
        });

        // A quotation did not carry a UOM before this migration. The old conversion
        // contract always treated its quantity as the variant base UOM, so that exact
        // historical meaning can be preserved without inventing a conversion.
        DB::table('quotation_lines')
            ->join('product_variants', 'product_variants.id', '=', 'quotation_lines.product_variant_id')
            ->select('quotation_lines.id', 'quotation_lines.quantity', 'product_variants.unit_id')
            ->orderBy('quotation_lines.id')
            ->each(function (object $line): void {
                if (! is_numeric($line->unit_id) || ! is_numeric($line->quantity)) {
                    return;
                }

                DB::table('quotation_lines')
                    ->where('id', $line->id)
                    ->update([
                        'unit_id' => (int) $line->unit_id,
                        'transaction_quantity' => $line->quantity,
                        'transaction_unit_id' => (int) $line->unit_id,
                        'conversion_factor_snapshot' => '1.000000',
                        'base_quantity' => $line->quantity,
                    ]);
            });

        // Existing order lines already named a transaction unit but had no conversion
        // contract. Leave their snapshots nullable rather than guessing a factor; all
        // newly-created order lines are required by the application service to snapshot.
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropUnique('order_lines_order_variant_unit_unique');
            $table->decimal('quantity', 15, 3)->change();
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn(['transaction_quantity', 'conversion_factor_snapshot', 'base_quantity']);
            $table->unique(['order_id', 'product_variant_id']);
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex('order_lines_order_id_support_index');
        });

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->change();
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['transaction_quantity', 'conversion_factor_snapshot', 'base_quantity']);
        });
    }
};
