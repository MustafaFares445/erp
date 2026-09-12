<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * `invoices.inventory_operation_id` was removed from its origin migration by WP-4.2
 * (GAP-MW-13's deprecated column, superseded by {@see InvoiceDeliveryLink}), so there is no
 * legacy column left to backfill from. This asserts the join table is the sole invoice-to-delivery
 * relationship and correctly enforces "a delivery is invoiced at most once".
 */
it('creates the join table as the sole invoice-to-delivery relationship, with no legacy column left behind', function (): void {
    expect(Schema::hasTable('invoice_delivery_links'))->toBeTrue()
        ->and(Schema::hasColumns('invoice_delivery_links', ['id', 'invoice_id', 'inventory_operation_id']))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'inventory_operation_id'))->toBeFalse();
});

it('rejects a second invoice linked to the same delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);

    $firstInvoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);
    InvoiceDeliveryLink::query()->create([
        'invoice_id' => $firstInvoice->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
    ]);

    $secondInvoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);

    InvoiceDeliveryLink::query()->create([
        'invoice_id' => $secondInvoice->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
    ]);
})->throws(QueryException::class);
