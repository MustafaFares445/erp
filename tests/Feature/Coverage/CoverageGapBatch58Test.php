<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\WriteOffStatus;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\DepositApplicationIssue;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\InvoiceLine;
use App\Models\PriceFloorOverride;
use App\Models\ProductVariant;
use App\Models\ReceivableWriteOff;
use App\Models\Unit;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function coverage58Children(object $component): array
{
    $property = new ReflectionProperty($component, 'childComponents');
    $value = $property->getValue($component)['default'] ?? [];

    return is_array($value) ? $value : [];
}

function coverage58Find(object $component, string $name): ?object
{
    if (method_exists($component, 'getName') && $component->getName() === $name) {
        return $component;
    }

    foreach (coverage58Children($component) as $child) {
        $found = coverage58Find($child, $name);

        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function coverage58Closure(object $component, string $property): Closure
{
    $reflection = new ReflectionProperty($component, $property);
    $value = $reflection->getValue($component);

    expect($value)->toBeInstanceOf(Closure::class);

    return $value;
}

it('covers invoice pricing-floor labels and outstanding breakdown details', function (): void {
    $isBelowFloor = new ReflectionMethod(InvoiceInfolist::class, 'isBelowFloor');
    $pricingStatus = new ReflectionMethod(InvoiceInfolist::class, 'pricingStatusLabel');
    $outstanding = new ReflectionMethod(InvoicesTable::class, 'outstandingBreakdown');

    $within = new InvoiceLine;
    $within->forceFill([
        'unit_price' => '12.00',
        'floor_price_minor' => 1000,
    ]);

    expect($isBelowFloor->invoke(null, $within))->toBeFalse()
        ->and($pricingStatus->invoke(null, $within))->toBe('Within allowed pricing range');

    $below = new InvoiceLine;
    $below->forceFill([
        'unit_price' => '9.00',
        'floor_price_minor' => 1000,
    ]);

    expect($isBelowFloor->invoke(null, $below))->toBeTrue()
        ->and($pricingStatus->invoke(null, $below))->toBe('Approved exception (pending approval record)');

    $approver = User::factory()->create(['name' => 'Coverage Approver']);
    $override = new PriceFloorOverride;
    $override->setRelation('approvedBy', $approver);

    $below->setRelation('priceFloorOverride', $override);

    expect($pricingStatus->invoke(null, $below))
        ->toBe('Approved exception (override by Coverage Approver)');

    $writeOff = new ReceivableWriteOff;
    $writeOff->forceFill([
        'status' => WriteOffStatus::Approved,
        'amount_minor' => 300,
    ]);

    $invoice = new Invoice;
    $invoice->forceFill([
        'amount_paid' => '10.00',
        'credited_amount' => '5.00',
    ]);
    $invoice->setRelation('writeOffs', new Collection([$writeOff]));

    expect($outstanding->invoke(null, $invoice))
        ->toBe('Paid: 10.00 · Credited: 5.00 · Written off: 3.00');
});

it('covers invoice reconciliation banner and delivery URL closure', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued->value,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);
    DepositApplicationIssue::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'resolved_at' => null,
    ]);

    $banner = new ReflectionMethod(InvoiceInfolist::class, 'bannerMeta');
    $meta = $banner->invoke(null, $invoice->refresh());

    expect($meta['status'])->toBe('warning')
        ->and($meta['heading'])->toBe('Reconciliation issue');

    $operation = InventoryOperation::factory()->delivery()->done()->create();
    $link = new InvoiceDeliveryLink;
    $link->setRelation('inventoryOperation', $operation);

    $section = new ReflectionMethod(InvoiceInfolist::class, 'deliveriesSection')->invoke(null);
    $delivery = coverage58Find($section, 'inventoryOperation.operation_number');

    expect($delivery)->not->toBeNull();

    $url = coverage58Closure($delivery, 'url');

    expect($url($link))->toContain((string) $operation->getKey());

    $link->setRelation('inventoryOperation', null);
    expect($url($link))->toBeNull();
});

it('covers confirmed account credit-note banner and return-line source details', function (): void {
    $credit = new CreditNote;
    $credit->forceFill([
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => now(),
        'reversed_at' => null,
        'grand_total' => '25.00',
    ]);
    $credit->setRelation('invoice', null);

    $banner = new ReflectionMethod(CreditNoteInfolist::class, 'bannerMeta');
    $meta = $banner->invoke(null, $credit);

    expect($meta['status'])->toBe('success')
        ->and($meta['description'])->toBe(
            __('admin.sales.credit_note_ui.confirmed_account_effect', ['amount' => MoneyFormatter::formatAmount('25.00')]),
        );

    $inventoryReturn = InventoryReturn::factory()->create([
        'return_number' => 'RET-COV-058',
    ]);
    $variant = ProductVariant::factory()->create(['sku' => 'SKU-COV-058']);
    $unit = Unit::factory()->create(['name' => 'piece']);

    $returnLine = new InventoryReturnLine;
    $returnLine->forceFill(['transaction_quantity' => '2.000000']);
    $returnLine->setRelation('inventoryReturn', $inventoryReturn);
    $returnLine->setRelation('productVariant', $variant);
    $returnLine->setRelation('transactionUnit', $unit);

    $line = new CreditNoteLine;
    $line->forceFill(['description' => 'Coverage line']);
    $line->setRelation('inventoryReturnLine', $returnLine);

    $lineSource = new ReflectionMethod(CreditNoteInfolist::class, 'lineSource');
    $label = $lineSource->invoke(null, $line);

    expect($label)
        ->toContain('RET-COV-058')
        ->toContain('SKU-COV-058')
        ->toContain('2')
        ->toContain('piece');

    $section = new ReflectionMethod(CreditNoteInfolist::class, 'lines')->invoke(null);
    $sourceEntry = coverage58Find($section, 'source_reference');

    expect($sourceEntry)->not->toBeNull();

    $url = coverage58Closure($sourceEntry, 'url');
    expect($url($line))->toContain((string) $inventoryReturn->getKey());

    $line->setRelation('inventoryReturnLine', null);
    $line->setRelation('creditNote', $credit);

    expect($url($line))->toBeNull();
});

it('covers credit-note PDF availability and route generation', function (): void {
    Storage::fake('local');

    $credit = CreditNote::factory()->create([
        'status' => CreditNoteStatus::Confirmed->value,
        'confirmed_at' => now(),
    ]);

    $credit
        ->addMedia(UploadedFile::fake()->create('credit-note.pdf', 1, 'application/pdf'))
        ->toMediaCollection('credit-note-pdf');

    $route = new ReflectionMethod(CreditNoteInfolist::class, 'pdfRoute');

    expect($route->invoke(null, $credit->refresh(), 'preview'))
        ->toContain((string) $credit->getKey());

    $section = new ReflectionMethod(CreditNoteInfolist::class, 'document')->invoke(null);
    $pdfEntry = coverage58Find($section, 'credit_note_pdf');

    expect($pdfEntry)->not->toBeNull();

    $state = coverage58Closure($pdfEntry, 'getConstantStateUsing');

    expect($state($credit->refresh()))
        ->toBe(__('admin.sales.credit_note_ui.pdf_available'));
});
