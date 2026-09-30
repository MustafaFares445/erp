<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('prefills an active supplier from the create-page query string', function (): void {
    (new PurchasePermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);
    $supplier = Supplier::factory()->create(['is_active' => true]);

    request()->query->set('supplier_id', (string) $supplier->getKey());

    $component = Livewire::actingAs($actor)
        ->test(CreatePurchaseOrder::class)
        ->assertSuccessful();

    $state = $component->instance()->getSchema('form')->getRawState();

    if ($state instanceof Arrayable) {
        $state = $state->toArray();
    }

    expect($state['supplier_id'] ?? null)->toBe($supplier->getKey());
});

it('ignores invalid or inactive supplier query parameters', function (): void {
    (new PurchasePermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);
    $inactive = Supplier::factory()->create(['is_active' => false]);

    request()->query->set('supplier_id', (string) $inactive->getKey());

    $inactiveComponent = Livewire::actingAs($actor)
        ->test(CreatePurchaseOrder::class)
        ->assertSuccessful();

    $inactiveState = $inactiveComponent->instance()->getSchema('form')->getRawState();

    if ($inactiveState instanceof Arrayable) {
        $inactiveState = $inactiveState->toArray();
    }

    expect($inactiveState['supplier_id'] ?? null)->toBeNull();

    request()->query->set('supplier_id', 'not-numeric');

    $invalidComponent = Livewire::actingAs($actor)
        ->test(CreatePurchaseOrder::class)
        ->assertSuccessful();

    $invalidState = $invalidComponent->instance()->getSchema('form')->getRawState();

    if ($invalidState instanceof Arrayable) {
        $invalidState = $invalidState->toArray();
    }

    expect($invalidState['supplier_id'] ?? null)->toBeNull();
});
