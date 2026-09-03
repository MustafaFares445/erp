<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Exceptions\Domain\SelfConfirmationRejected;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function makerCheckerInventoryUser(string $roleName, array $permissions): User
{
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('rejects self confirmation before any stock movement can be posted', function (): void {
    $maker = makerCheckerInventoryUser('adjustment-maker-checker', [
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);

    $adjustment = InventoryAdjustment::factory()->create([
        'created_by' => $maker->getKey(),
    ]);

    expect(fn () => app(InventoryAdjustmentService::class)->confirm($adjustment, $maker))
        ->toThrow(SelfConfirmationRejected::class);

    expect($adjustment->fresh()?->isDraft())->toBeTrue()
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('keeps permission and maker checker controls independent', function (): void {
    $maker = makerCheckerInventoryUser('adjustment-maker', [
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);
    $checker = makerCheckerInventoryUser('adjustment-checker', [
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);
    $viewer = makerCheckerInventoryUser('adjustment-viewer-only', [
        InventoryPermission::AdjustmentView->value,
    ]);

    $adjustment = InventoryAdjustment::factory()->create([
        'created_by' => $maker->getKey(),
    ]);

    expect($maker->can('confirm', $adjustment))->toBeFalse()
        ->and($checker->can('confirm', $adjustment))->toBeTrue()
        ->and($viewer->can('confirm', $adjustment))->toBeFalse();
});
