<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\RefundStatus;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\Widgets\CreditNotesOverview;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function creditNoteSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::BillingOfficer->value);

    return $user;
}

it('scopes credit notes confirmed this month', function (): void {
    CreditNote::factory()->create(['status' => 'confirmed', 'confirmed_at' => now()]);
    CreditNote::factory()->create(['status' => 'confirmed', 'confirmed_at' => now()->subMonths(2)]);
    CreditNote::factory()->create(['status' => 'draft']);

    expect(CreditNote::query()->confirmedThisMonth()->count())->toBe(1);
});

it('renders the credit notes overview stats widget and list page', function (): void {
    CreditNote::factory()->create(['status' => 'confirmed', 'confirmed_at' => now(), 'grand_total' => 250]);
    CreditNote::factory()->create(['status' => 'draft']);

    Livewire::actingAs(creditNoteSalesUser())
        ->test(CreditNotesOverview::class)
        ->assertSuccessful();

    Livewire::actingAs(creditNoteSalesUser())
        ->test(ListCreditNotes::class)
        ->assertSuccessful()
        ->assertSee('Confirmed this month')
        ->assertSee('Sales returns');
});

it('shows the credit note reason and distinguishes a credit from a cash refund', function (): void {
    $creditNote = CreditNote::factory()->create(['status' => 'draft']);

    Livewire::actingAs(creditNoteSalesUser())
        ->test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
        ->assertSuccessful()
        ->assertSee('Credit note and cash refund are separate records')
        ->assertSee('This credit note is a draft');
});

it('shows actual refunds separately and flags a possible overpayment after credit', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'issued_at' => now(),
        'total_amount' => 100,
        'amount_paid' => 100,
        'credited_amount' => 20,
    ]);
    $creditNote = CreditNote::factory()->for($customer, 'customer')->create([
        'invoice_id' => $invoice->getKey(),
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'grand_total' => 20,
    ]);
    Refund::factory()->for($creditNote, 'creditNote')->create([
        'customer_id' => $customer->getKey(),
        'status' => RefundStatus::Paid,
        'amount' => 20,
        'paid_at' => now(),
    ]);

    Livewire::actingAs(creditNoteSalesUser())
        ->test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
        ->assertSuccessful()
        ->assertSee('Review a possible customer refund or account credit')
        ->assertSee('Actual cash refunds')
        ->assertSee('Paid')
        ->assertSee('20.00');
});

it('filters credit notes by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = CreditNote::factory()->for($matching, 'customer')->create();
    $unwanted = CreditNote::factory()->for($other, 'customer')->create();

    Livewire::actingAs(creditNoteSalesUser())
        ->test(ListCreditNotes::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'customer-rule' => [
                    'type' => 'customer',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$matching->getKey()]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});
