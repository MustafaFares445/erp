<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryReturn;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('does not expose phase 4 stock-changing create actions to view-only operators', function (): void {
    $operator = User::factory()->admin()->create();
    $operator->givePermissionTo([
        InventoryPermission::CountView->value,
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::CorrectionView->value,
        InventoryPermission::ReturnView->value,
        InventoryPermission::ConditionChangeView->value,
    ]);
    $this->actingAs($operator);

    expect(InventoryCountResource::canCreate())->toBeFalse()
        ->and(AdjustmentResource::canCreate())->toBeFalse()
        ->and(InventoryCorrectionResource::canCreate())->toBeFalse()
        ->and(ReturnResource::canCreate())->toBeFalse()
        ->and(InventoryConditionChangeResource::canCreate())->toBeFalse();
});

it('preserves a posted return document as immutable history', function (): void {
    $posted = InventoryReturn::factory()->posted()->create(['notes' => 'Original posted evidence']);

    expect(fn () => $posted->update(['notes' => 'Overwritten by an operator']))
        ->toThrow(DomainException::class, 'immutable');
    expect($posted->fresh()->notes)->toBe('Original posted evidence');
});
