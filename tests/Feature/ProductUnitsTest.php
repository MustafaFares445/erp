<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('updates a product allowed units and its default unit', function (): void {
    $manager = productUnitsAdministrator();
    $product = Product::factory()->create();
    $initialUnit = Unit::factory()->create(['name' => 'Initial unit']);
    $replacementUnits = Unit::factory()->count(2)->create();
    $product->syncUnits([$initialUnit->getKey()]);

    Livewire::actingAs($manager)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'unit_ids' => $replacementUnits->pluck('id')->all(),
            'default_unit_id' => $replacementUnits->last()->getKey(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $savedProduct = $product->fresh();

    expect($savedProduct->units()->pluck('units.id')->all())
        ->toBe($replacementUnits->pluck('id')->all())
        ->and($savedProduct->units()->wherePivot('is_default', true)->value('units.id'))
        ->toBe($replacementUnits->last()->getKey());
});

it('returns only active units assigned to the selected product', function (): void {
    $product = Product::factory()->create();
    $allowedUnit = Unit::factory()->create(['name' => 'Allowed unit']);
    $secondAllowedUnit = Unit::factory()->create(['name' => 'Second allowed unit']);
    $inactiveUnit = Unit::factory()->create(['name' => 'Inactive unit', 'is_active' => false]);
    $unrelatedUnit = Unit::factory()->create(['name' => 'Unrelated unit']);
    $product->syncUnits([$allowedUnit->getKey(), $secondAllowedUnit->getKey(), $inactiveUnit->getKey()]);

    $unitOptions = new ReflectionMethod(OperationLinesRepeater::class, 'unitOptions');
    $singleUnitId = new ReflectionMethod(OperationLinesRepeater::class, 'singleUnitId');

    expect($unitOptions->invoke(null, $product->getKey()))
        ->toBe([
            $allowedUnit->getKey() => 'Allowed unit',
            $secondAllowedUnit->getKey() => 'Second allowed unit',
        ])
        ->not->toHaveKey($inactiveUnit->getKey())
        ->not->toHaveKey($unrelatedUnit->getKey())
        ->and($singleUnitId->invoke(null, $product->getKey()))
        ->toBeNull();

    $product->syncUnits([$allowedUnit->getKey()]);

    expect($singleUnitId->invoke(null, $product->getKey()))->toBe($allowedUnit->getKey());
});

function productUnitsAdministrator(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);

    return $manager;
}
