<?php

declare(strict_types=1);

use App\Enums\CreditNoteStockConsequence;
use App\Enums\DashboardRole;
use App\Enums\InventoryReturnStatus;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\Returns\ReturnResource;
use App\Jobs\GenerateCreditNoteDocument;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();

    $settings = SalesSetting::current();
    $settings->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
    ])->save();

    FiscalPeriod::factory()->create();
});

function filamentCreditNoteActor(): User
{
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::SystemAdmin->value);

    return $actor;
}

it('offers only the selected customer posted returns when creating a credit note', function (): void {
    $actor = filamentCreditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $posted = InventoryReturn::factory()->posted()->for($customer, 'customer')->create();
    InventoryReturn::factory()->for($customer, 'customer')->create();
    InventoryReturn::factory()->posted()->for($otherCustomer, 'customer')->create();

    $component = Livewire::actingAs($actor)
        ->test(CreateCreditNote::class)
        ->fillForm([
            'customer_id' => (string) $customer->getKey(),
            'stock_consequence' => CreditNoteStockConsequence::GoodsReturned->value,
        ]);
    $returnField = collect($component->instance()->getSchema('form')->getFlatComponents(withHidden: true))
        ->first(static fn (mixed $field): bool => $field instanceof Select && $field->getName() === 'inventory_return_id');

    expect($returnField)->toBeInstanceOf(Select::class);
    expect($returnField->getOptions())->toBe([$posted->getKey() => $posted->return_number]);

});

/** @return array{0: Invoice, 1: InvoiceLine} */
function filamentIssuedInvoiceWithLine(CustomerProfile $customer): array
{
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'subtotal' => 100,
        'tax_total' => 0,
        'total_amount' => 100,
        'amount_paid' => 0,
    ]);
    $invoice->forceFill(['issued_at' => now(), 'status' => 'issued'])->save();

    $line = $invoice->lines()->create([
        'description' => 'Widget',
        'quantity' => '2.000',
        'unit_price' => '50.00',
        'tax_amount' => '0.00',
        'line_total' => '100.00',
        'sort_order' => 1,
    ]);

    return [$invoice->refresh(), $line];
}

it('shows return provenance and preview and download links for an available credit note PDF', function (): void {
    config()->set('filesystems.disks.credit-note-resource-tests', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/credit-note-resource-tests'),
    ]);
    Storage::fake('credit-note-resource-tests');
    $actor = filamentCreditNoteActor();
    $customer = CustomerProfile::factory()->create();
    $return = InventoryReturn::factory()->for($customer, 'customer')->create();
    $returnLine = InventoryReturnLine::factory()->for($return, 'inventoryReturn')->create([
        'posted_base_quantity' => '1.000000',
    ]);
    $return->forceFill([
        'status' => InventoryReturnStatus::Posted,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ])->save();
    $creditNote = CreditNote::factory()->for($customer, 'customer')->create([
        'inventory_return_id' => $return->getKey(),
        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned,
        'subtotal' => '20.00',
        'grand_total' => '20.00',
    ]);
    CreditNoteLine::factory()->for($creditNote, 'creditNote')->create([
        'inventory_return_line_id' => $returnLine->getKey(),
        'description' => 'Returned item',
        'quantity' => '1.000',
        'unit_price' => '20.00',
        'line_total' => '20.00',
    ]);
    $media = $creditNote->addMediaFromString('%PDF-1.7 credit note fixture')
        ->usingFileName('credit-note.pdf')
        ->toMediaCollection('credit-note-pdf', 'credit-note-resource-tests');

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
        ->assertSee($return->return_number)
        ->assertSeeHtml(ReturnResource::getUrl('view', ['record' => $return]))
        ->assertSee(__('admin.sales.credit_note_ui.pdf_available'))
        ->assertSeeHtml(route('admin.credit-notes.media.preview', ['creditNote' => $creditNote, 'media' => $media]))
        ->assertSeeHtml(route('admin.credit-notes.media.download', ['creditNote' => $creditNote, 'media' => $media]));
});

it('shows lifecycle actions only for the matching credit note status', function (): void {
    $actor = filamentCreditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = filamentIssuedInvoiceWithLine($customer);

    $draft = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $draft, 'Line', 1.0, 40.0, 0.0, $invoiceLine);

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $draft->getKey()])
        ->assertActionVisible('confirm')
        ->assertActionHidden('reverse')
        ->assertActionHidden('generate_pdf');

    $confirmed = app(CreditNoteService::class)->confirm($actor, $draft);

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $confirmed->getKey()])
        ->assertActionHidden('confirm')
        ->assertActionVisible('reverse')
        ->assertActionVisible('generate_pdf');

    // A confirmed credit note fails the `update` policy outright, so the Edit
    // page itself refuses to mount for it — a stronger guarantee than hiding
    // the delete button would be.
    $this->actingAs($actor)
        ->get(CreditNoteResource::getUrl('edit', ['record' => $confirmed]))
        ->assertForbidden();
});

it('confirms a draft credit note through the view page action', function (): void {
    $actor = filamentCreditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = filamentIssuedInvoiceWithLine($customer);

    $draft = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $draft, 'Line', 1.0, 40.0, 0.0, $invoiceLine);

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $draft->getKey()])
        ->callAction('confirm')
        ->assertHasNoActionErrors();

    expect($draft->refresh()->isConfirmed())->toBeTrue();
});

it('denies credit note confirmation to a view-only role', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = filamentIssuedInvoiceWithLine($customer);

    $actor = filamentCreditNoteActor();
    $draft = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $draft, 'Line', 1.0, 40.0, 0.0, $invoiceLine);

    Livewire::actingAs($reviewer)
        ->test(ViewCreditNote::class, ['record' => $draft->getKey()])
        ->assertActionHidden('confirm');
});

it('queues credit note PDFs and reverses confirmed notes through page actions', function (): void {
    Queue::fake();
    $actor = filamentCreditNoteActor();
    $customer = CustomerProfile::factory()->create();
    [$invoice, $invoiceLine] = filamentIssuedInvoiceWithLine($customer);

    $draft = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    app(CreditNoteService::class)->addLine($actor, $draft, 'Line', 1.0, 40.0, 0.0, $invoiceLine);
    $confirmed = app(CreditNoteService::class)->confirm($actor, $draft);

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $confirmed->getKey()])
        ->callAction('generate_pdf')
        ->assertHasNoActionErrors();

    Queue::assertPushed(GenerateCreditNoteDocument::class);

    Livewire::actingAs($actor)
        ->test(ViewCreditNote::class, ['record' => $confirmed->getKey()])
        ->callAction('reverse')
        ->assertHasNoActionErrors();

    expect($confirmed->refresh()->isReversed())->toBeTrue();
});
