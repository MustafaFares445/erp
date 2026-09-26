<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Widgets\CreditNotesOverview;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
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
        ->assertSuccessful();
});

it('filters credit notes by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = CreditNote::factory()->for($matching, 'customer')->create();
    $unwanted = CreditNote::factory()->for($other, 'customer')->create();

    Livewire::actingAs(creditNoteSalesUser())
        ->test(ListCreditNotes::class)
        ->filterTable('customer_id', $matching->getKey())
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});
