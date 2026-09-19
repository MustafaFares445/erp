<?php

declare(strict_types=1);

use App\Enums\CountScope;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryCounts\Pages\CreateInventoryCount;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Filament\Resources\InventoryCounts\RelationManagers\InventoryCountLinesRelationManager;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCountService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function inventoryCountActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::CountView->value,
        InventoryPermission::CountOpen->value,
        InventoryPermission::CountRecord->value,
        InventoryPermission::CountConfirm->value,
    ]);

    return $user;
}

/** @return array{0: ProductVariant, 1: Warehouse} */
function seededCountStock(): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'available_quantity' => '10.000000',
    ]);

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
    ]);

    InventoryLotBalance::query()->firstOrNew([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
    ])->forceFill([
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '0.000000',
    ])->save();

    return [$variant, $warehouse];
}

it('opens a count from the create form and renders it on the list and view pages', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();

    Livewire::actingAs($actor)
        ->test(ListInventoryCounts::class)
        ->assertCanSeeTableRecords([$count]);

    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->assertSuccessful();
});

it('records a count from the lines relation manager', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $line = $count->lines()->sole();

    Livewire::actingAs($actor)
        ->test(InventoryCountLinesRelationManager::class, [
            'ownerRecord' => $count,
            'pageClass' => ViewInventoryCount::class,
        ])
        ->assertCanSeeTableRecords([$line])
        ->callAction(TestAction::make('record_count')->table($line), data: ['quantity' => '10'])
        ->assertHasNoActionErrors();

    expect($line->refresh()->counted_base_quantity)->toBe('10.000000');
});

it('requests and accepts recounts from the count lines relation manager', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $line = $count->lines()->sole();

    Livewire::actingAs($actor)
        ->test(InventoryCountLinesRelationManager::class, [
            'ownerRecord' => $count,
            'pageClass' => ViewInventoryCount::class,
        ])
        ->callAction(TestAction::make('request_recount')->table($line), data: ['reason' => 'Coverage recount'])
        ->assertHasNoActionErrors();

    expect($line->refresh()->recount_requested)->toBeTrue()
        ->and($line->note)->toBe('Coverage recount');

    Livewire::actingAs($actor)
        ->test(InventoryCountLinesRelationManager::class, [
            'ownerRecord' => $count,
            'pageClass' => ViewInventoryCount::class,
        ])
        ->assertActionVisible(TestAction::make('accept_variance')->table($line->refresh()))
        ->callAction(TestAction::make('accept_variance')->table($line->refresh()), data: ['reason' => 'Coverage accepted'])
        ->assertHasNoActionErrors();

    expect($line->refresh()->recount_requested)->toBeFalse()
        ->and($line->note)->toBe('Coverage accepted');
});

it('downloads and reimports physical count sheets including rejected rows', function (): void {
    Storage::fake('local');

    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();
    $service = app(InventoryCountService::class);

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $line = $count->lines()->sole();

    $page = app(ViewInventoryCount::class);
    $download = new ReflectionMethod(ViewInventoryCount::class, 'downloadCountSheet');
    $response = $download->invoke($page, $count);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('line_id')
        ->and($csv)->toContain((string) $line->getKey())
        ->and($csv)->toContain((string) $line->system_base_quantity);

    $upload = new ReflectionMethod(ViewInventoryCount::class, 'uploadCounts');

    auth()->logout();
    expect(fn (): mixed => $upload->invoke($page, $count, 'inventory-count-uploads/missing.csv'))
        ->toThrow(LogicException::class, 'authenticated inventory count actor');

    $this->actingAs($actor);
    expect(fn (): mixed => $upload->invoke($page, $count, 'inventory-count-uploads/missing.csv'))
        ->toThrow(LogicException::class, 'could not be read');

    Storage::disk('local')->put(
        'inventory-count-uploads/rejected.csv',
        "line_id,sku,variant,lot,serial,condition,system_quantity,counted_quantity
999999,,,,,,,5
{$line->getKey()},,,,,,,
",
    );
    $upload->invoke($page, $count, 'inventory-count-uploads/rejected.csv');
    expect(Storage::disk('local')->exists('inventory-count-uploads/rejected.csv'))->toBeFalse();

    Storage::disk('local')->put(
        'inventory-count-uploads/valid.csv',
        "line_id,sku,variant,lot,serial,condition,system_quantity,counted_quantity
{$line->getKey()},,,,,,,10
",
    );
    $upload->invoke($page, $count, 'inventory-count-uploads/valid.csv');

    expect($line->refresh()->counted_base_quantity)->toBe('10.000000')
        ->and(Storage::disk('local')->exists('inventory-count-uploads/valid.csv'))->toBeFalse();

    // Keep the service reference alive in this coverage path as the upload method delegates to it.
    expect($service)->toBeInstanceOf(InventoryCountService::class);
});

it('submits and confirms a count through the view-page actions', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $line = $count->lines()->sole();

    app(InventoryCountService::class)->recordCount($line, '10', $actor);

    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->assertActionVisible('download_count_sheet')
        ->assertActionVisible('upload_counts')
        ->assertActionVisible('submit')
        ->callAction('submit', ['partial' => false])
        ->assertHasNoActionErrors();

    expect($count->refresh()->isPendingReview())->toBeTrue();

    $confirmer = inventoryCountActor();

    Livewire::actingAs($confirmer)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->assertActionVisible('confirm')
        ->callAction('confirm')
        ->assertHasNoActionErrors();

    expect($count->refresh()->status->isTerminal())->toBeTrue();
});

it('covers count cancellation and view-action input guards', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $page = app(ViewInventoryCount::class);

    $runAction = new ReflectionMethod(ViewInventoryCount::class, 'runCountAction');
    auth()->logout();
    expect(fn (): mixed => $runAction->invoke($page, static fn (): null => null, 'coverage'))
        ->toThrow(LogicException::class, 'authenticated inventory count actor');

    $actions = collect($page->getHeaderActions())->keyBy(fn ($action): string => $action->getName());

    $uploadAction = $actions->get('upload_counts');
    $uploadClosure = $uploadAction?->getActionFunction();
    expect($uploadClosure)->toBeInstanceOf(Closure::class);
    expect(fn (): mixed => $uploadClosure($count, ['sheet' => null]))
        ->toThrow(LogicException::class, 'count sheet file is required');

    $cancelAction = $actions->get('cancel');
    $cancelClosure = $cancelAction?->getActionFunction();
    expect($cancelClosure)->toBeInstanceOf(Closure::class);
    expect(fn (): mixed => $cancelClosure($count, ['reason' => null]))
        ->toThrow(LogicException::class, 'cancellation reason is required');

    $this->actingAs($actor);
    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->callAction('cancel', ['reason' => 'Coverage cancellation'])
        ->assertHasNoActionErrors();

    expect($count->refresh()->status->isTerminal())->toBeTrue();
});
