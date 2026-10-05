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

it('creates a receipt correction from the queue without optional notes', function (): void {
    $user = correctionLifecycleUser();
    $receipt = \App\Models\InventoryOperation::factory()->receipt()->done()->create();
    Livewire::actingAs($user)->test(ManageInventoryCorrections::class)
        ->callAction(CreateAction::class, [
            'original_inventory_operation_id' => $receipt->id,
            'reason' => 'Correct the receipt quantity.',
        ])
        ->assertHasNoActionErrors();
    $correction = InventoryCorrection::query()->where('original_inventory_operation_id', $receipt->id)->sole();
    expect($correction->notes)->toBeNull()
        ->and($correction->reason)->toBe('Correct the receipt quantity.');
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

    $operations = collect($inventory['items'])->firstWhere('label', 'admin.sections.operations');

    $tab = collect($operations['tabs'])
        ->first(fn (array $entry): bool => $entry['link'] === InventoryCorrectionResource::class);

    expect($tab)->toBeArray()
        ->and($tab['label'])->toBe('admin.inventory.correction.resource_label_plural');
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

it('cancels a draft correction through the view action', function (): void {
    $user = correctionLifecycleUser();
    $draft = InventoryCorrection::factory()->create();

    Livewire::actingAs($user)
        ->test(ViewInventoryCorrection::class, ['record' => $draft->getKey()])
        ->callAction('cancel', ['reason' => 'Coverage cancellation reason'])
        ->assertNotified();

    expect($draft->refresh()->status)->toBe(InventoryCorrectionStatus::Cancelled)
        ->and($draft->cancellation_reason)->toBe('Coverage cancellation reason');
});

it('requires an authenticated actor to run a correction action', function (): void {
    auth()->logout();

    $page = new ReflectionClass(ViewInventoryCorrection::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewInventoryCorrection::class, 'runCorrectionAction');

    expect(fn (): mixed => $method->invoke($page, static fn (): null => null, 'coverage.notification'))
        ->toThrow(LogicException::class, 'authenticated inventory correction actor');
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
