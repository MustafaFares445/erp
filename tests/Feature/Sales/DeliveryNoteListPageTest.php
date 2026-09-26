<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Widgets\DeliveryNotesOverview;
use App\Models\InventoryOperation;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function deliveryNoteSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

it('scopes deliveries to the delivery operation type only', function (): void {
    InventoryOperation::factory()->delivery()->ready()->create();
    InventoryOperation::factory()->receipt()->ready()->create();

    expect(InventoryOperation::query()->deliveries()->count())->toBe(1);
});

it('scopes ready-to-dispatch deliveries', function (): void {
    InventoryOperation::factory()->delivery()->ready()->create();
    InventoryOperation::factory()->delivery()->waiting()->create();
    InventoryOperation::factory()->delivery()->done()->create();

    expect(InventoryOperation::query()->readyToDispatch()->count())->toBe(1);
});

it('scopes delivered-but-not-invoiced deliveries', function (): void {
    InventoryOperation::factory()->delivery()->done()->create();
    InventoryOperation::factory()->delivery()->ready()->create();

    expect(InventoryOperation::query()->deliveredNotInvoiced()->count())->toBe(1);
});

it('renders the delivery notes overview stats widget and list page', function (): void {
    InventoryOperation::factory()->delivery()->ready()->create();
    InventoryOperation::factory()->delivery()->done()->create();

    Livewire::actingAs(deliveryNoteSalesUser())
        ->test(DeliveryNotesOverview::class)
        ->assertSuccessful();

    Livewire::actingAs(deliveryNoteSalesUser())
        ->test(ListDeliveryNotes::class)
        ->assertSuccessful();
});
