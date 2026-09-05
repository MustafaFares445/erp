<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Filament\Resources\Taxes\TaxResource;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\InvoicePostingService;
use Carbon\CarbonImmutable;
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

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->sole()->getKey(),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->sole()->getKey(),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->sole()->getKey(),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->sole()->getKey(),
        'customer_deposits_account_id' => ChartAccount::query()->where('code', '2400')->sole()->getKey(),
        'bad_debt_expense_account_id' => ChartAccount::query()->where('code', '6800')->sole()->getKey(),
    ])->save();

    FiscalPeriod::factory()->create();

    $this->chief = User::factory()->admin()->create();
    $this->chief->assignRole(DashboardRole::SystemAdmin->value);

    $this->customer = CustomerProfile::factory()->create(['company_name' => 'Acme Trading']);
});

function taxRegisterPageIssueInvoice(User $actor, int $customerId, CarbonImmutable $date, string $subtotal, string $tax, string $total): Invoice
{
    $invoice = Invoice::factory()->create([
        'customer_id' => $customerId,
        'invoice_date' => $date->toDateString(),
        'due_date' => $date->addDays(30)->toDateString(),
        'subtotal' => $subtotal,
        'tax_total' => $tax,
        'total_amount' => $total,
        'status' => 'draft',
        'issued_at' => null,
    ]);

    app(InvoicePostingService::class)->post($actor, $invoice);
    $invoice->forceFill(['status' => 'sent', 'issued_at' => now(), 'sent_at' => now()])->save();

    return $invoice->refresh();
}

it('denies access to a user without the tax view permission', function (): void {
    $stranger = User::factory()->employee()->create();

    $this->actingAs($stranger)
        ->get(ViewTaxRegister::getUrl())
        ->assertForbidden();
});

it('grants access to a user holding the tax view permission', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(AccountingPermission::TaxView->value);

    $this->actingAs($viewer)
        ->get(ViewTaxRegister::getUrl())
        ->assertOk();
});

it('renders the period summary and a reconciled reconciliation panel', function (): void {
    taxRegisterPageIssueInvoice($this->chief, (int) $this->customer->getKey(), CarbonImmutable::today(), '1000.00', '100.00', '1100.00');

    Livewire::actingAs($this->chief)
        ->test(ViewTaxRegister::class)
        ->assertSuccessful()
        ->assertSee('100.00')
        ->assertSee('Reconciled');
});

it('flags the reconciliation panel as unreconciled when a stray manual journal entry diverges', function (): void {
    taxRegisterPageIssueInvoice($this->chief, (int) $this->customer->getKey(), CarbonImmutable::today(), '500.00', '50.00', '550.00');

    $cash = ChartAccount::query()->where('code', '1110')->sole();
    $deferred = ChartAccount::query()->where('code', '2350')->sole();

    $entry = app(JournalPostingService::class)->draft(
        $this->chief,
        CarbonImmutable::today(),
        [
            ['chart_account_id' => $cash->getKey(), 'debit' => '15.00', 'credit' => '0.00'],
            ['chart_account_id' => $deferred->getKey(), 'debit' => '0.00', 'credit' => '15.00'],
        ],
        'Out-of-band adjustment',
    );
    app(JournalPostingService::class)->post($this->chief, $entry);

    Livewire::actingAs($this->chief)
        ->test(ViewTaxRegister::class)
        ->assertSuccessful()
        ->assertSee('Difference:');
});

it('downloads the summary CSV export without error', function (): void {
    taxRegisterPageIssueInvoice($this->chief, (int) $this->customer->getKey(), CarbonImmutable::today(), '200.00', '20.00', '220.00');

    Livewire::actingAs($this->chief)
        ->test(ViewTaxRegister::class)
        ->callAction('export_summary')
        ->assertHasNoActionErrors();
});

it('downloads the entries CSV export without error', function (): void {
    taxRegisterPageIssueInvoice($this->chief, (int) $this->customer->getKey(), CarbonImmutable::today(), '200.00', '20.00', '220.00');

    Livewire::actingAs($this->chief)
        ->test(ViewTaxRegister::class)
        ->callAction('export_entries')
        ->assertHasNoActionErrors();
});

it('registers the report page alongside the raw list on the tax resource', function (): void {
    expect(TaxResource::getPages())->toHaveKey('register');
});
