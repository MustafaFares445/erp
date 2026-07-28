<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('renders the inventory operations index with an enum stage', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('index'))
        ->assertOk()
        ->assertSee($operation->stage->label());
});
