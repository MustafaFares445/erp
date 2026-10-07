<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\TrackingMode;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\Performance\Pages\ListPerformanceScores;
use App\Filament\Resources\Performance\Tables\PerformanceTable;
use App\Filament\Resources\PriceLists\Pages\ManagePriceLists;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Models\Currency;
use App\Models\EmployeePerformanceScore;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\InventoryLotService;
use App\Services\Purchasing\ReplenishmentPurchaseOrderDraftService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function residualPurchaseNeedsAction(string $name): Action
{
    $page = app(PurchaseNeeds::class);
    $actions = (new ReflectionMethod(PurchaseNeeds::class, 'getHeaderActions'))->invoke($page);
    $action = collect($actions)->first(
        static fn (mixed $candidate): bool => $candidate instanceof Action && $candidate->getName() === $name,
    );

    if (! $action instanceof Action) {
        throw new LogicException("Missing Purchase Needs action {$name}");
    }

    return $action;
}

function residualReplenishmentFixture(bool $withTransfer = false, ?int $leadTime = 3): array
{
    $target = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->create([
        'warehouse_id' => $target->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
    ]);

    if ($withTransfer) {
        $source = Warehouse::factory()->create();
        InventoryStock::factory()->create([
            'warehouse_id' => $source->getKey(),
            'product_variant_id' => $variant->getKey(),
            'on_hand_quantity' => 50,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 50,
        ]);
        WarehouseReplenishmentPolicy::query()->create([
            'warehouse_id' => $source->getKey(),
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => 5,
            'max_quantity' => 20,
            'is_active' => true,
        ]);
    }

    $supplier = Supplier::factory()->create(['default_lead_time_days' => $leadTime]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'purchase_cost' => '12.50',
        'availability_status' => 'active',
        'is_active' => true,
        'lead_time_days' => $leadTime,
    ]);

    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $target->getKey(),
        'product_variant_id' => $variant->getKey(),
        'preferred_supplier_id' => $supplier->getKey(),
        'min_quantity' => 5,
        'max_quantity' => 20,
        'is_active' => true,
    ]);

    $requirement = ReplenishmentRequirement::query()
        ->where('warehouse_replenishment_policy_id', $policy->getKey())
        ->active()
        ->sole();

    return [$target, $variant, $supplier, $policy, $requirement];
}

it('covers Purchase Needs replenishment options and draft action success', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [, , , , $requirement] = residualReplenishmentFixture();

    $options = (new ReflectionMethod(PurchaseNeeds::class, 'replenishmentRequirementOptions'))->invoke(null);

    expect($options)->toHaveKey($requirement->getKey())
        ->and($options[$requirement->getKey()])->toContain('REQ-'.$requirement->getKey());

    $action = residualPurchaseNeedsAction('createFromReplenishment');
    $callback = $action->getActionFunction();

    expect($callback)->toBeInstanceOf(Closure::class);

    $before = PurchaseOrder::query()->count();
    $callback(['requirement_ids' => [$requirement->getKey()]]);

    expect(PurchaseOrder::query()->count())->toBe($before + 1);

    auth()->logout();
    $before = PurchaseOrder::query()->count();
    $callback(['requirement_ids' => [$requirement->getKey()]]);
    expect(PurchaseOrder::query()->count())->toBe($before);
});

it('covers replenishment draft zero-demand transfer and deleted-variant guards', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $service = app(ReplenishmentPurchaseOrderDraftService::class);

    [, $variant, , , $covered] = residualReplenishmentFixture();
    $covered->forceFill([
        'covered_base_quantity' => $covered->required_base_quantity,
        'status' => 'open',
    ])->saveQuietly();

    expect(fn () => $service->createDrafts($actor, [(int) $covered->getKey()]))
        ->toThrow(ValidationException::class, 'already covered');

    [, , , , $transferRequirement] = residualReplenishmentFixture(true);
    $external = new ReflectionMethod(ReplenishmentPurchaseOrderDraftService::class, 'externalPurchaseBaseQuantity');
    expect((float) $external->invoke($service, $transferRequirement))->toBeGreaterThanOrEqual(0.0);

    [, $deletedVariant, , , $deletedRequirement] = residualReplenishmentFixture();
    $deletedVariant->delete();

    expect(fn () => $service->createDrafts($actor, [(int) $deletedRequirement->getKey()]))
        ->toThrow(ValidationException::class, 'no product variant');
});

it('covers replenishment draft zero lead-time expected date branch', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    [, , , , $requirement] = residualReplenishmentFixture(false, null);
    $orders = app(ReplenishmentPurchaseOrderDraftService::class)
        ->createDrafts($actor, [(int) $requirement->getKey()]);

    expect($orders)->toHaveCount(1)
        ->and($orders->first()->expected_at)->toBeNull();
});

it('covers performance month filter invalid and valid date paths', function (): void {
    $owner = app(ListPerformanceScores::class);
    $table = PerformanceTable::configure(Table::make($owner));
    $filter = $table->getFilters()['period'] ?? null;

    expect($filter)->toBeInstanceOf(Filter::class);

    $base = EmployeePerformanceScore::query();
    $same = $filter->apply(clone $base, ['month' => 'definitely-not-a-date']);
    expect($same->toSql())->toBe($base->toSql());

    $filtered = $filter->apply(EmployeePerformanceScore::query(), ['month' => '2026-10-01']);
    $sql = $filtered->toSql();

    expect($sql)->toContain('period_start');
});

it('covers price-list variant options and pricing actor permission branches', function (): void {
    $productVariant = ProductVariant::factory()->create();
    $otherVariant = ProductVariant::factory()->create();

    $priceList = PriceList::query()->create([
        'name' => 'Coverage Price List',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    $schema = PriceListResource::form(Schema::make(app(ManagePriceLists::class))->model($priceList));
    $repeater = collect($schema->getFlatComponents(withHidden: true))
        ->first(static fn (mixed $component): bool => $component instanceof Repeater
            && $component->getName() === 'items');

    expect($repeater)->toBeInstanceOf(Repeater::class);

    $field = collect($repeater->getDefaultChildComponents())
        ->first(static fn (mixed $component): bool => $component instanceof Select
            && $component->getName() === 'product_variant_id');

    expect($field)->toBeInstanceOf(Select::class);

    $options = (new ReflectionProperty($field, 'options'))->getValue($field);
    expect($options)->toBeInstanceOf(Closure::class);

    $invalid = Mockery::mock(Get::class);
    $invalid->shouldReceive('__invoke')->with('product_id')->andReturn('invalid');
    expect($options($invalid))->toBe([]);

    $valid = Mockery::mock(Get::class);
    $valid->shouldReceive('__invoke')->with('product_id')->andReturn($productVariant->product_id);
    $resolved = $options($valid);

    expect($resolved)->toHaveKey($productVariant->getKey())
        ->and($resolved)->not->toHaveKey($otherVariant->getKey());

    $actorCan = new ReflectionMethod(PriceListResource::class, 'actorCan');
    auth()->logout();
    expect($actorCan->invoke(null, InventoryPermission::PricingView))->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect($actorCan->invoke(null, InventoryPermission::PricingView))->toBeTrue();

    Role::findOrCreate(DashboardRole::WarehouseManager->value, 'web');
    $roleUser = User::factory()->admin()->create();
    $roleUser->assignRole(DashboardRole::WarehouseManager->value);
    $this->actingAs($roleUser);
    expect($actorCan->invoke(null, InventoryPermission::PricingView))->toBeBool();
});

it('covers inventory FEFO identifier mismatch and expiry guards', function (): void {
    $service = app(InventoryLotService::class);

    $unsaved = new ProductVariant;
    $unsaved->forceFill([
        'track_serials' => false,
        'track_batches' => true,
        'tracks_expiration' => true,
        'track_expiry' => true,
        'tracking_mode' => TrackingMode::Lot,
    ]);

    expect(fn () => $service->preferredFefoLot($unsaved, 1, '1.000000'))
        ->toThrow(LogicException::class, 'identifiers must be integers');

    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $other = ProductVariant::factory()->expiryMaterial()->create();

    $mismatch = InventoryLot::factory()->create([
        'product_variant_id' => $other->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'expires_at' => today()->addDays(10),
    ]);

    expect(fn () => $service->assertFefoSelection(
        $mismatch,
        $variant,
        (int) $warehouse->getKey(),
        '1.000000',
        null,
    ))->toThrow(DomainException::class);

    $noExpiry = InventoryLot::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'expires_at' => null,
    ]);

    expect(fn () => $service->assertFefoSelection(
        $noExpiry,
        $variant,
        (int) $warehouse->getKey(),
        '1.000000',
        null,
    ))->toThrow(DomainException::class);
});

it('covers transfer-only and unsourced replenishment option branches', function (): void {
    Gate::before(static fn (): bool => true);
    $this->actingAs(User::factory()->admin()->create());

    [, , , , $transferRequirement] = residualReplenishmentFixture(true);

    $options = (new ReflectionMethod(PurchaseNeeds::class, 'replenishmentRequirementOptions'))->invoke(null);
    expect($options)->not->toHaveKey($transferRequirement->getKey());

    $rows = app(PurchaseNeeds::class)->needs();
    expect(collect($rows)->pluck('source_reference'))->not->toContain('REQ-'.$transferRequirement->getKey());

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
    ]);

    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'preferred_supplier_id' => null,
        'min_quantity' => 5,
        'max_quantity' => 20,
        'is_active' => true,
    ]);

    $unsourced = ReplenishmentRequirement::query()
        ->where('warehouse_replenishment_policy_id', $policy->getKey())
        ->active()
        ->sole();

    $options = (new ReflectionMethod(PurchaseNeeds::class, 'replenishmentRequirementOptions'))->invoke(null);

    expect($options)->not->toHaveKey($unsourced->getKey());
});
