<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\Pages\ViewInventoryConditionChange;
use App\Models\InventoryConditionChange;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pageConditionChangeCoverageRecord(User $actor): InventoryConditionChange
{
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();

    return InventoryConditionChange::query()->create([
        'document_number' => 'ICC-'.fake()->unique()->numerify('######'),
        'type' => InventoryConditionChangeType::Disposal,
        'status' => InventoryConditionChangeStatus::Draft,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Damaged,
        'condition_to' => StockCondition::Disposed,
        'base_quantity' => 1,
        'reason_category' => ConditionChangeReason::Other,
        'reason' => 'Coverage page disposal',
        'created_by' => $actor->getKey(),
    ]);
}

it('attaches disposal evidence and cancels the condition change through page actions', function (): void {
    Gate::before(static fn (): bool => true);
    Storage::fake('local');

    $actor = User::factory()->admin()->create();
    $change = pageConditionChangeCoverageRecord($actor);
    $path = UploadedFile::fake()->image('page-evidence.jpg')
        ->storeAs('disposal-evidence', 'page-evidence.jpg', 'local');

    Livewire::actingAs($actor)
        ->test(ViewInventoryConditionChange::class, ['record' => $change->getKey()])
        ->callAction('attach_evidence', ['evidence' => [$path]])
        ->assertNotified();

    expect($change->refresh()->getMedia('disposal-evidence'))->toHaveCount(1);

    Livewire::actingAs($actor)
        ->test(ViewInventoryConditionChange::class, ['record' => $change->getKey()])
        ->callAction('cancel', ['reason' => 'Coverage cancellation'])
        ->assertNotified();

    expect($change->refresh()->status)->toBe(InventoryConditionChangeStatus::Cancelled)
        ->and($change->reason)->toContain('Coverage cancellation');
});

it('requires an authenticated actor for condition-change service actions', function (): void {
    auth()->logout();

    $page = new ReflectionClass(ViewInventoryConditionChange::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ViewInventoryConditionChange::class, 'runConditionChangeAction');

    expect(fn (): mixed => $method->invoke(
        $page,
        static fn (): null => null,
        'Coverage success',
    ))->toThrow(LogicException::class, 'authenticated inventory condition-change actor');
});

it('covers condition-change page raw input guards', function (): void {
    Gate::before(static fn (): bool => true);
    Storage::fake('local');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $change = pageConditionChangeCoverageRecord($actor);

    $page = new ReflectionClass(ViewInventoryConditionChange::class)->newInstanceWithoutConstructor();
    $actions = collect($page->getHeaderActions())->keyBy(static fn ($action): string => $action->getName());

    $attach = $actions->get('attach_evidence');
    expect($attach)->not->toBeNull();
    $attach->record($change)->call(['data' => ['evidence' => 'not-an-array']]);

    $cancel = $actions->get('cancel');
    expect($cancel)->not->toBeNull();

    expect(fn (): mixed => $cancel->record($change)->call(['data' => ['reason' => 123]]))
        ->toThrow(LogicException::class, 'A cancellation reason is required.');
});
