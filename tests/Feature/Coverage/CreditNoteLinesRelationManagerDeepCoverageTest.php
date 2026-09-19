<?php

declare(strict_types=1);

use App\Enums\CreditNoteStockConsequence;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\RelationManagers\CreditNoteLinesRelationManager;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function creditNoteLinesManager(CreditNote $creditNote): CreditNoteLinesRelationManager
{
    $manager = new CreditNoteLinesRelationManager;
    $manager->ownerRecord = $creditNote;
    $manager->pageClass = ViewCreditNote::class;

    return $manager;
}

function creditNoteLinesMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(CreditNoteLinesRelationManager::class, $name);
}

it('adds and deletes a credit note line through relation manager actions', function (): void {
    $actor = User::factory()->create();
    $invoice = Invoice::factory()->create();
    $invoiceLine = InvoiceLine::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'description' => 'Original invoice line',
        'quantity' => '2.000000',
        'unit_price' => '25.00',
        'tax_amount' => '2.50',
        'line_total' => '52.50',
    ]);
    $creditNote = CreditNote::factory()->create([
        'customer_id' => $invoice->customer_id,
        'invoice_id' => $invoice->getKey(),
        'stock_consequence' => CreditNoteStockConsequence::NotApplicable,
    ]);

    Livewire::actingAs($actor)
        ->test(CreditNoteLinesRelationManager::class, [
            'ownerRecord' => $creditNote,
            'pageClass' => ViewCreditNote::class,
        ])
        ->callTableAction('addLine', data: [
            'invoice_line_id' => $invoiceLine->getKey(),
            'inventory_return_line_id' => null,
            'description' => 'Credit adjustment',
            'quantity' => 1,
            'unit_price' => 25,
            'tax_amount' => 1.25,
        ])
        ->assertHasNoActionErrors();

    $line = CreditNoteLine::query()->sole();

    expect($line->invoice_line_id)->toBe($invoiceLine->getKey())
        ->and((float) $line->line_total)->toBe(26.25);

    Livewire::actingAs($actor)
        ->test(CreditNoteLinesRelationManager::class, [
            'ownerRecord' => $creditNote->refresh(),
            'pageClass' => ViewCreditNote::class,
        ])
        ->callTableAction('delete', $line)
        ->assertHasNoActionErrors();

    expect(CreditNoteLine::query()->count())->toBe(0);
});

it('covers invoice and return line options plus defensive helpers', function (): void {
    $invoice = Invoice::factory()->create();
    $invoiceLine = InvoiceLine::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'description' => '',
        'quantity' => '3.000000',
        'unit_price' => '10.00',
        'tax_amount' => '0.00',
        'line_total' => '30.00',
        'sort_order' => 1,
    ]);
    $return = InventoryReturn::factory()->customer()->create();
    $variant = ProductVariant::factory()->create();
    $returnLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $creditNote = CreditNote::factory()->create([
        'customer_id' => $invoice->customer_id,
        'invoice_id' => $invoice->getKey(),
        'inventory_return_id' => $return->getKey(),
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
    ]);

    $manager = creditNoteLinesManager($creditNote);

    $invoiceOptions = creditNoteLinesMethod('invoiceLineOptions')->invoke($manager);
    $returnOptions = creditNoteLinesMethod('inventoryReturnLineOptions')->invoke($manager);

    expect($invoiceOptions)->toHaveKey($invoiceLine->getKey())
        ->and($invoiceOptions[$invoiceLine->getKey()])->toContain('Line')
        ->and($returnOptions)->toHaveKey($returnLine->getKey())
        ->and($returnOptions[$returnLine->getKey()])->toContain($variant->sku)
        ->and($returnOptions[$returnLine->getKey()])->toContain('remaining 2.000000');

    $empty = CreditNote::factory()->create([
        'invoice_id' => null,
        'inventory_return_id' => null,
    ]);
    $emptyManager = creditNoteLinesManager($empty);

    expect(creditNoteLinesMethod('invoiceLineOptions')->invoke($emptyManager))->toBe([])
        ->and(creditNoteLinesMethod('inventoryReturnLineOptions')->invoke($emptyManager))->toBe([])
        ->and(fn (): mixed => creditNoteLinesMethod('integerKey')->invoke(null, new CreditNoteLine))
        ->toThrow(LogicException::class, 'integer identifiers');

    $invalid = new CreditNoteLinesRelationManager;
    $invalid->ownerRecord = new ProductVariant;

    expect(fn (): mixed => creditNoteLinesMethod('creditNoteRecord')->invoke($invalid))
        ->toThrow(LogicException::class, 'Expected a CreditNote');
});
