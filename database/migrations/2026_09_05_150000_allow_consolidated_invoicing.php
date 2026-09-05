<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Introduces `invoice_delivery_links` as the control that a delivery is invoiced at most
     * once, across every invoice including standalone ones (WP-2.13, GAP-MW-13). Today that
     * control lives on `invoices.inventory_operation_id`'s unique index, which a standalone
     * invoice bypasses entirely because it carries no delivery reference at all. Backfilling one
     * link row per already-linked invoice, then moving the control to the join table's unique
     * index, closes that gap without losing any existing linkage.
     */
    public function up(): void
    {
        Schema::create('invoice_delivery_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('inventory_operation_id')->unique()->constrained('inventory_operations')->restrictOnDelete();
            $table->timestamps();
            $table->index('invoice_id');
        });

        $linkedInvoices = DB::table('invoices')
            ->whereNotNull('inventory_operation_id')
            ->orderBy('id')
            ->get(['id', 'inventory_operation_id']);

        $now = now();

        $rows = $linkedInvoices->map(fn (object $invoice): array => [
            'invoice_id' => $invoice->id,
            'inventory_operation_id' => $invoice->inventory_operation_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('invoice_delivery_links')->insert($rows);
        }

        $backfilled = DB::table('invoice_delivery_links')->count();

        if ($backfilled !== $linkedInvoices->count()) {
            throw new RuntimeException(sprintf(
                'Consolidated invoicing backfill mismatch: expected %d invoice_delivery_links row(s), found %d.',
                $linkedInvoices->count(),
                $backfilled,
            ));
        }

        // A plain index keeps the foreign key satisfied once the unique index below is dropped.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index('inventory_operation_id', 'invoices_inventory_operation_id_index');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['inventory_operation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_inventory_operation_id_index');
            $table->unique('inventory_operation_id');
        });

        Schema::dropIfExists('invoice_delivery_links');
    }
};
