<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the join table and drops the single-delivery unique index without dropping the column', function (): void {
    expect(Schema::hasTable('invoice_delivery_links'))->toBeTrue()
        ->and(Schema::hasColumns('invoice_delivery_links', ['id', 'invoice_id', 'inventory_operation_id']))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'inventory_operation_id'))->toBeTrue();
});

/**
 * The migration's backfill promise is that every pre-existing invoice already linked to a
 * delivery via the deprecated `invoices.inventory_operation_id` column gains exactly one
 * {@see InvoiceDeliveryLink} row, with the total count matching. This reverses the migration back
 * to its pre-change schema — reproducing invoices as they existed beforehand — then re-runs it.
 */
it('backfills exactly one delivery link per pre-existing linked invoice, with the count matching', function (): void {
    $migration = require database_path('migrations/2026_09_05_150000_allow_consolidated_invoicing.php');

    $migration->down();

    $customer = CustomerProfile::factory()->create();
    $linkedOperations = InventoryOperation::factory()->delivery()->done()->count(3)->create([
        'customer_id' => $customer->getKey(),
    ]);

    $linkedInvoices = $linkedOperations->map(
        fn (InventoryOperation $operation): Invoice => Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'inventory_operation_id' => $operation->getKey(),
        ]),
    );

    // An invoice carrying no delivery reference at all must not gain a phantom link.
    Invoice::factory()->create(['customer_id' => $customer->getKey(), 'inventory_operation_id' => null]);

    $migration->up();

    expect(DB::table('invoice_delivery_links')->count())->toBe($linkedInvoices->count());

    foreach ($linkedInvoices as $invoice) {
        $link = InvoiceDeliveryLink::query()->where('invoice_id', $invoice->getKey())->sole();

        expect($link->inventory_operation_id)->toBe($invoice->inventory_operation_id);
    }
});
