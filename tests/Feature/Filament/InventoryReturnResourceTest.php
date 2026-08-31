<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryReturn;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
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
