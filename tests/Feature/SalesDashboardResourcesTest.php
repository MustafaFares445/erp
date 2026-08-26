<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\InventoryOperation;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function salesDashboardAdmin(): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);

    return $admin;
}

it('renders every newly-backed sales resource page for an authorized administrator', function (): void {
    $admin = salesDashboardAdmin();

    foreach ([
        ListDeliveryNotes::class,
        ListInvoices::class,
        ListPayments::class,
        ListCreditNotes::class,
        ListPaymentMethods::class,
    ] as $page) {
        Livewire::actingAs($admin)->test($page)->assertSuccessful();
    }
});

it('shows only delivery inventory operations on the Sales delivery notes page', function (): void {
    $admin = salesDashboardAdmin();
    $delivery = InventoryOperation::factory()->delivery()->create();
    $receipt = InventoryOperation::factory()->receipt()->create();

    Livewire::actingAs($admin)
        ->test(ListDeliveryNotes::class)
        ->assertCanSeeTableRecords([$delivery])
        ->assertCanNotSeeTableRecords([$receipt]);
});
