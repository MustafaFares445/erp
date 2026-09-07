<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\SalesPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
});

it('uses the canonical return document instead of redirecting to stock movements', function (): void {
    $user = returnLifecycleUser();
    $draft = InventoryReturn::factory()->create();

    expect(ReturnResource::getModel())->toBe(InventoryReturn::class);

    $this->actingAs($user)
        ->get(ReturnResource::getUrl('index'))
        ->assertOk();

    Livewire::actingAs($user)
        ->test(ManageReturns::class)
        ->assertCanSeeTableRecords([$draft])
        ->assertActionVisible(CreateAction::class);

    $manageSource = (string) file_get_contents(
        app_path('Filament/Resources/Returns/Pages/ManageReturns.php'),
    );

    expect($manageSource)
        ->not->toContain('StockMovementResource')
        ->not->toContain('redirect(')
        ->toContain('InventoryReturnService::class');
});

it('denies return creation to a read-only return viewer', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ReturnView->value);

    $this->actingAs($viewer);

    expect(ReturnResource::canCreate())->toBeFalse();

    Livewire::actingAs($viewer)
        ->test(ManageReturns::class)
        ->assertActionHidden(CreateAction::class);
});

it('shows lifecycle actions only for the matching return state and permission', function (): void {
    $user = returnLifecycleUser();
    $draft = InventoryReturn::factory()->create();
    $ready = InventoryReturn::factory()->ready()->create();
    $posted = InventoryReturn::factory()->posted()->create();

    Livewire::actingAs($user)
        ->test(ViewReturn::class, ['record' => $draft->getKey()])
        ->assertActionVisible('markReady')
        ->assertActionHidden('post')
        ->assertActionVisible('cancel');

    Livewire::actingAs($user)
        ->test(ViewReturn::class, ['record' => $ready->getKey()])
        ->assertActionHidden('markReady')
        ->assertActionVisible('post')
        ->assertActionVisible('cancel');

    Livewire::actingAs($user)
        ->test(ViewReturn::class, ['record' => $posted->getKey()])
        ->assertActionHidden('markReady')
        ->assertActionHidden('post')
        ->assertActionHidden('cancel');
});

function returnLifecycleUser(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        InventoryPermission::ReturnView->value,
        InventoryPermission::ReturnCreate->value,
        InventoryPermission::ReturnInspect->value,
        InventoryPermission::ReturnPost->value,
        InventoryPermission::ReturnCancel->value,
    ]);

    return $user;
}

it('registers returns in the inventory operations module section', function (): void {
    $inventory = collect(AdminModuleRegistry::groups())
        ->firstWhere('key', 'inventory');

    expect($inventory)->toBeArray();

    $returns = collect($inventory['items'])
        ->first(fn (array $item): bool => $item['link'] === ReturnResource::class);

    expect($returns)->toBeArray()
        ->and($returns['label'])->toBe('admin.resources.returns')
        ->and($returns['section'])->toBe('operations');
});

it('shows create credit note only when a posted customer return has invoice evidence and sales permission', function (): void {
    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'total_amount' => '100.00',
    ]);
    $invoice->forceFill([
        'status' => 'issued',
        'issued_at' => now(),
    ])->save();

    $return = InventoryReturn::factory()->customer()->posted()->create([
        'customer_id' => $customer->getKey(),
        'original_inventory_operation_id' => $delivery->getKey(),
        'credit_note_required' => true,
    ]);

    // Both actors are given the Reviewer role before their permissions:
    // `User::factory()->admin()` defaults to an admin user type, and an admin
    // holding no fixed dashboard role keeps the blanket admin-bypass
    // (specs/019-sales-lifecycle-payments-credits/contracts/permissions.md
    // §4), which would make `CreditNoteResource::canCreate()` return true for
    // both regardless of the sales permission under test. Reviewer carries
    // CreditNoteView but not CreditNoteManage, so it narrows each actor down
    // to exactly what is granted below.
    $actor = returnLifecycleUser();
    $actor->assignRole(DashboardRole::Reviewer->value);
    $actor->givePermissionTo(SalesPermission::CreditNoteManage->value);

    Livewire::actingAs($actor)
        ->test(ViewReturn::class, ['record' => $return->getKey()])
        ->assertActionVisible('createCreditNote');

    $inventoryOnly = returnLifecycleUser();
    $inventoryOnly->assignRole(DashboardRole::Reviewer->value);

    Livewire::actingAs($inventoryOnly)
        ->test(ViewReturn::class, ['record' => $return->getKey()])
        ->assertActionHidden('createCreditNote');
});
