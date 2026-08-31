<?php

declare(strict_types=1);

use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCorrections\Pages\ManageInventoryCorrections;
use App\Filament\Resources\InventoryCorrections\Pages\ViewInventoryCorrection;
use App\Models\InventoryCorrection;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('exposes canonical corrections as an inventory operations resource', function (): void {
    $user = correctionLifecycleUser();
    $draft = InventoryCorrection::factory()->create();

    expect(InventoryCorrectionResource::getModel())->toBe(InventoryCorrection::class);

    $this->actingAs($user)
        ->get(InventoryCorrectionResource::getUrl('index'))
        ->assertOk();

    Livewire::actingAs($user)
        ->test(ManageInventoryCorrections::class)
        ->assertCanSeeTableRecords([$draft])
        ->assertActionVisible(CreateAction::class);

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');

    expect($inventory)->toBeArray();

    $item = collect($inventory['items'])
        ->first(fn (array $entry): bool => $entry['link'] === InventoryCorrectionResource::class);

    expect($item)->toBeArray()
        ->and($item['label'])->toBe('admin.resources.corrections')
        ->and($item['section'])->toBe('operations');
});

it('denies correction creation to a read-only correction viewer', function (): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::CorrectionView->value);

    $this->actingAs($viewer);

    expect(InventoryCorrectionResource::canCreate())->toBeFalse();

    Livewire::actingAs($viewer)
        ->test(ManageInventoryCorrections::class)
        ->assertActionHidden(CreateAction::class);
});

it('shows post and cancel only while a correction is draft', function (): void {
    $user = correctionLifecycleUser();
    $draft = InventoryCorrection::factory()->create();
    $posted = InventoryCorrection::factory()->posted()->create();
    $cancelled = InventoryCorrection::factory()->cancelled()->create();

    Livewire::actingAs($user)
        ->test(ViewInventoryCorrection::class, ['record' => $draft->getKey()])
        ->assertActionVisible('post')
        ->assertActionVisible('cancel');

    foreach ([$posted, $cancelled] as $terminal) {
        Livewire::actingAs($user)
            ->test(ViewInventoryCorrection::class, ['record' => $terminal->getKey()])
            ->assertActionHidden('post')
            ->assertActionHidden('cancel');
    }

    expect($posted->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and($cancelled->status)->toBe(InventoryCorrectionStatus::Cancelled);
});

function correctionLifecycleUser(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        InventoryPermission::CorrectionView->value,
        InventoryPermission::CorrectionCreate->value,
        InventoryPermission::CorrectionPost->value,
        InventoryPermission::CorrectionCancel->value,
    ]);

    return $user;
}
