<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Sales\CreditNoteService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
