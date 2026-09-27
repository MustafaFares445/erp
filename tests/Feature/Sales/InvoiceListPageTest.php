<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Widgets\InvoicesOverview;
use App\Models\CustomerProfile;
use App\Models\DepositApplicationIssue;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function invoiceSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

it('scopes unpaid invoices to active ones with nothing paid or credited', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'draft', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);

    expect(Invoice::query()->unpaid()->count())->toBe(1);
});

it('scopes partially paid invoices to active ones with some payment applied', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0]);

    expect(Invoice::query()->partiallyPaid()->count())->toBe(1);
});

it('scopes overdue invoices to active ones past due date with a balance remaining', function (): void {
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);

    expect(Invoice::query()->overdue()->count())->toBe(1);
});

it('scopes settled invoices to active ones with nothing left to collect', function (): void {
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 100]);
    Invoice::factory()->create(['status' => 'issued', 'total_amount' => 100, 'amount_paid' => 50, 'credited_amount' => 0]);

    expect(Invoice::query()->settled()->count())->toBe(2);
});

it('scopes invoices needing attention to overdue or unresolved deposit issues', function (): void {
    $overdue = Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->subDays(5),
    ]);
    $withOpenIssue = Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 0, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);
    DepositApplicationIssue::query()->create([
        'invoice_id' => $withOpenIssue->getKey(),
        'error_message' => 'coverage',
        'occurred_at' => now(),
    ]);
    Invoice::factory()->create([
        'status' => 'issued', 'total_amount' => 100, 'amount_paid' => 100, 'credited_amount' => 0,
        'due_date' => today()->addDays(5),
    ]);

    expect(Invoice::query()->needsAttention()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$overdue->id, $withOpenIssue->id])->sort()->values()->all());
});

it('scopes invoices issued this month', function (): void {
    Invoice::factory()->create(['issued_at' => now()]);
    Invoice::factory()->create(['issued_at' => now()->subMonths(2)]);
    Invoice::factory()->create(['issued_at' => null]);

    expect(Invoice::query()->issuedThisMonth()->count())->toBe(1);
});

it('renders the invoices overview stats widget and list page', function (): void {
    Invoice::factory()->create(['issued_at' => now(), 'status' => 'issued']);

    $widget = Livewire::actingAs(invoiceSalesUser())
        ->test(InvoicesOverview::class)
        ->assertSuccessful();

    $widget
        ->assertSee('--cols-default: repeat(1, minmax(0, 1fr))')
        ->assertSee('--cols-cmd: repeat(2, minmax(0, 1fr))')
        ->assertSee('--cols-cxl: repeat(3, minmax(0, 1fr))')
        ->assertSee('--cols-c5xl: repeat(5, minmax(0, 1fr))');

    Livewire::actingAs(invoiceSalesUser())
        ->test(ListInvoices::class)
        ->assertSuccessful();
});

it('filters invoices by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = Invoice::factory()->for($matching, 'customer')->create();
    $unwanted = Invoice::factory()->for($other, 'customer')->create();

    Livewire::actingAs(invoiceSalesUser())
        ->test(ListInvoices::class)
        ->filterTable('customer_id', $matching->getKey())
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});
