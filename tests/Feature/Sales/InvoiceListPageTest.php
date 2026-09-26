<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Widgets\InvoicesOverview;
use App\Models\CustomerProfile;
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

it('scopes invoices issued this month', function (): void {
    Invoice::factory()->create(['issued_at' => now()]);
    Invoice::factory()->create(['issued_at' => now()->subMonths(2)]);
    Invoice::factory()->create(['issued_at' => null]);

    expect(Invoice::query()->issuedThisMonth()->count())->toBe(1);
});

it('renders the invoices overview stats widget and list page', function (): void {
    Invoice::factory()->create(['issued_at' => now(), 'status' => 'issued']);

    Livewire::actingAs(invoiceSalesUser())
        ->test(InvoicesOverview::class)
        ->assertSuccessful();

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
