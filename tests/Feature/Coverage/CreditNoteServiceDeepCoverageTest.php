<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\InventoryReturnStatus;
use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CustomerProfile;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function creditCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(CreditNoteService::class, $method)
        ->invoke(app(CreditNoteService::class), ...$arguments);
}
it('covers source invoice line ownership and confirmed note guards', function (): void {
    $actor = User::factory()->create();
    $invoiceA = Invoice::factory()->create();
    $invoiceB = Invoice::factory()->create();
    $invoiceLine = InvoiceLine::factory()->create([
        'invoice_id' => $invoiceB->getKey(),
        'description' => 'Coverage source line',
        'quantity' => 1,
        'unit_price' => 100,
        'tax_amount' => 0,
        'line_total' => 100,
    ]);
    $note = CreditNote::factory()->create(['invoice_id' => $invoiceA->getKey()]);

    expect(fn () => app(CreditNoteService::class)->addLine(
        $actor,
        $note,
        'Mismatch',
        1,
        10,
        0,
        $invoiceLine,
    ))->toThrow(DomainException::class, 'must belong to the source invoice');

    $confirmed = CreditNote::factory()->create([
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => now(),
    ]);
    expect(fn () => app(CreditNoteService::class)->confirm($actor, $confirmed))
        ->toThrow(DomainException::class, 'already confirmed');
});
it('covers invoice issuance and note total remaining guards', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $draftInvoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Draft,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'credited_amount' => '0.00',
    ]);
    $note = CreditNote::factory()->create([
        'invoice_id' => $draftInvoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    CreditNoteLine::factory()->create([
        'credit_note_id' => $note->getKey(),
        'invoice_line_id' => null,
        'description' => 'Standalone correction',
        'quantity' => 1,
        'unit_price' => 10,
        'tax_amount' => 0,
        'line_total' => 10,
    ]);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $note))
        ->toThrow(DomainException::class, 'only correct an issued invoice');

    $issued = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Sent,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'credited_amount' => '90.00',
        'issued_at' => now(),
    ]);
    $over = CreditNote::factory()->create([
        'invoice_id' => $issued->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    CreditNoteLine::factory()->create([
        'credit_note_id' => $over->getKey(),
        'invoice_line_id' => null,
        'description' => 'Over remaining',
        'quantity' => 1,
        'unit_price' => 20,
        'tax_amount' => 0,
        'line_total' => 20,
    ]);

    expect(fn () => app(CreditNoteService::class)->confirm($actor, $over))
        ->toThrow(DomainException::class, 'exceeds the invoice uncredited balance');
});
it('covers goods-returned stock consequence missing line link', function (): void {
    $customer = CustomerProfile::factory()->create();
    $return = InventoryReturn::factory()->customer()->posted()->create([
        'customer_id' => $customer->getKey(),
        'status' => InventoryReturnStatus::Posted,
    ]);
    $note = CreditNote::factory()->create([
        'customer_id' => $customer->getKey(),
        'inventory_return_id' => $return->getKey(),
        'reason_category' => CreditNoteReason::SalesReturn,
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);
    $line = CreditNoteLine::factory()->create([
        'credit_note_id' => $note->getKey(),
        'inventory_return_line_id' => null,
    ]);
    $note->load(['lines.inventoryReturnLine', 'inventoryReturn']);

    expect(fn (): mixed => creditCoverageInvoke('assertStockConsequence', $note))
        ->toThrow(DomainException::class, 'must link to an inventory return line');
});
it('covers return line invoice source product and delivery mismatch guards', function (): void {
    $note = new CreditNote;
    $note->forceFill(['inventory_return_id' => 10]);

    $returnLine = new InventoryReturnLine;
    $returnLine->forceFill([
        'inventory_return_id' => 10,
        'product_variant_id' => 20,
    ]);

    expect(fn (): mixed => creditCoverageInvoke('assertReturnLineMatchesNote', $note, $returnLine, null))
        ->toThrow(DomainException::class, 'identify its source invoice line');

    $invoiceLine = new InvoiceLine;
    $invoiceLine->forceFill([
        'product_variant_id' => 21,
        'order_line_id' => 30,
    ]);

    expect(fn (): mixed => creditCoverageInvoke('assertReturnLineMatchesNote', $note, $returnLine, $invoiceLine))
        ->toThrow(DomainException::class, 'different product variants');

    $invoiceLine->forceFill(['product_variant_id' => 20]);
    $deliveryLine = new InventoryOperationLine;
    $deliveryLine->forceFill(['order_line_id' => 31]);

    $returnLine->setRelation('originalOperationLine', $deliveryLine);

    expect(fn (): mixed => creditCoverageInvoke('assertReturnLineMatchesNote', $note, $returnLine, $invoiceLine))
        ->toThrow(DomainException::class, 'does not match the returned delivery line');
});
it('covers missing return lines negative quantities and missing invoice lines', function (): void {
    $note = CreditNote::factory()->create();

    $returnLinked = new CreditNoteLine;
    $returnLinked->forceFill([
        'inventory_return_line_id' => 999999,
        'quantity' => 1,
    ]);

    expect(fn (): mixed => creditCoverageInvoke('assertLineWithinReturnRemaining', $note, $returnLinked))
        ->toThrow(DomainException::class, 'no longer exists')
        ->and(fn (): mixed => creditCoverageInvoke('decimalQuantity', -1.0))
        ->toThrow(DomainException::class, 'cannot be negative');

    $missingInvoice = new CreditNoteLine;
    $missingInvoice->forceFill([
        'invoice_line_id' => 999999,
        'quantity' => 1,
        'line_total' => 1,
    ]);
    $missingInvoice->setRelation('invoiceLine', null);

    expect(fn (): mixed => creditCoverageInvoke('assertLineWithinRemaining', $note, $missingInvoice))
        ->toThrow(DomainException::class, 'invoice line no longer exists');
});
it('covers invoice line ownership and quantity remaining guards directly', function (): void {
    $invoiceA = Invoice::factory()->create();
    $invoiceB = Invoice::factory()->create();
    $sourceLine = InvoiceLine::factory()->create([
        'invoice_id' => $invoiceB->getKey(),
        'description' => 'Coverage source line',
        'quantity' => 1,
        'unit_price' => 100,
        'tax_amount' => 0,
        'line_total' => 100,
    ]);
    $note = CreditNote::factory()->create(['invoice_id' => $invoiceA->getKey()]);

    $line = new CreditNoteLine;
    $line->forceFill([
        'invoice_line_id' => $sourceLine->getKey(),
        'quantity' => 1,
        'line_total' => 10,
    ]);
    $line->setRelation('invoiceLine', $sourceLine);

    expect(fn (): mixed => creditCoverageInvoke('assertLineWithinRemaining', $note, $line))
        ->toThrow(DomainException::class, 'must belong to the source invoice');

    $note->forceFill(['invoice_id' => $invoiceB->getKey()]);
    $quantityLine = new CreditNoteLine;
    $quantityLine->forceFill([
        'invoice_line_id' => $sourceLine->getKey(),
        'quantity' => 2,
        'line_total' => 50,
    ]);
    $quantityLine->setRelation('invoiceLine', $sourceLine);

    expect(fn (): mixed => creditCoverageInvoke('assertLineWithinRemaining', $note, $quantityLine))
        ->toThrow(DomainException::class, 'exceeds the invoice line uncredited quantity');
});
