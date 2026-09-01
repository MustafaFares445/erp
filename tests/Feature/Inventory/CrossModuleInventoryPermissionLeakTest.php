<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\InventoryOperation;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new SupportPermissionSeeder)->run();
});

it('does not leak inventory permissions into purchasing sales or support roles', function (string $role): void {
    $user = User::factory()->admin()->create();
    $user->assignRole($role);

    $inventoryPermissions = $user->getAllPermissions()
        ->pluck('name')
        ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'inventory.'))
        ->values()
        ->all();

    expect($inventoryPermissions)->toBe([])
        ->and($user->can('create', InventoryOperation::class))->toBeFalse();
})->with([
    DashboardRole::PurchasingManager->value,
    DashboardRole::SalesManager->value,
    DashboardRole::SupportManager->value,
]);

it('keeps the Sales delivery-note bridge read-only and type-scoped', function (): void {
    $sales = User::factory()->admin()->create();
    $sales->assignRole(DashboardRole::SalesManager->value);

    $delivery = InventoryOperation::factory()->delivery()->create();
    $receipt = InventoryOperation::factory()->receipt()->create();
    $transfer = InventoryOperation::factory()->internalTransfer()->create();

    expect($sales->can('view', $delivery))->toBeTrue()
        ->and($sales->can('view', $receipt))->toBeFalse()
        ->and($sales->can('view', $transfer))->toBeFalse()
        ->and($sales->can('update', $delivery))->toBeFalse()
        ->and($sales->can('markReady', $delivery))->toBeFalse()
        ->and($sales->can('complete', $delivery))->toBeFalse();
});
