<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ConfirmationsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Inventory\InventoryLotService;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function coverageConfirmationManager(mixed $owner): ConfirmationsRelationManager
{
    $manager = new ReflectionClass(ConfirmationsRelationManager::class)->newInstanceWithoutConstructor();
    $manager->ownerRecord = $owner;
    $manager->pageClass = PurchaseOrderResource::class;

    return $manager;
}

function coverageConfirmationCreateAction(ConfirmationsRelationManager $manager): mixed
{
    $table = $manager->table(Table::make($manager));
    $actions = $table->getHeaderActions();

    expect($actions)->not->toBeEmpty();

    return $actions[0];
}

it('covers supplier confirmation relation manager authentication and owner guards', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create();
    $manager = coverageConfirmationManager($purchaseOrder);
    $action = coverageConfirmationCreateAction($manager);

    auth()->logout();

    expect(fn (): mixed => $action->process(null, ['data' => ['notes' => 'Coverage']]))
        ->toThrow(LogicException::class, 'authenticated actor');

    $this->actingAs(User::factory()->admin()->create());
    $badManager = coverageConfirmationManager(new ProductVariant);
    $method = new ReflectionMethod(ConfirmationsRelationManager::class, 'order');

    expect(fn (): mixed => $method->invoke($badManager))
        ->toThrow(LogicException::class, 'Expected a PurchaseOrder');
});

it('records supplier confirmation through the relation manager create action', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $purchaseOrder = PurchaseOrder::factory()->for($supplier)->create();
    $variant = ProductVariant::factory()->create();

    PurchaseOrderLine::factory()
        ->for($purchaseOrder)
        ->for($variant, 'productVariant')
        ->create([
            'unit_id' => $variant->unit_id,
            'quantity_ordered' => 5,
        ]);

    $manager = coverageConfirmationManager($purchaseOrder);
    $action = coverageConfirmationCreateAction($manager);

    $result = $action->process(null, ['data' => ['notes' => '  Coverage confirmation  ']]);

    expect($result)->toBeInstanceOf(SupplierConfirmation::class)
        ->and(SupplierConfirmation::query()->where('purchase_order_id', $purchaseOrder->getKey())->count())->toBe(1)
        ->and($result->notes)->toBe('  Coverage confirmation  ');
});

it('covers inventory lot non-batch and invalid identifier guards', function (): void {
    $service = app(InventoryLotService::class);
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $line = new InventoryOperationLine;
    $line->forceFill([
        'inventory_lot_id' => 123,
        'base_quantity' => '1.000000',
    ]);

    expect(fn () => $service->consume($line, $variant, (int) $warehouse->getKey(), null))
        ->toThrow(DomainException::class);

    expect(fn () => $service->assertReservable(new InventoryLot, (int) $warehouse->getKey(), '1.000000', null))
        ->toThrow(LogicException::class, 'identifiers must be integers');
});

it('resolves legacy inventory lot aliases to their canonical lot', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $canonical = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $variant->getKey(),
    ]);
    $alias = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $variant->getKey(),
        'canonical_inventory_lot_id' => $canonical->getKey(),
    ]);

    $resolved = new ReflectionMethod(InventoryLotService::class, 'lockCanonicalLot')
        ->invoke(app(InventoryLotService::class), (int) $alias->getKey(), $variant);

    expect($resolved)->toBeInstanceOf(InventoryLot::class)
        ->and($resolved->getKey())->toBe($canonical->getKey());
});

it('exports tax register rows to csv', function (): void {
    $entry = TaxRecognitionEntry::factory()->create([
        'tax_date' => today(),
        'direction' => 'input',
        'tax_type' => 'coverage_tax',
        'tax_amount' => '12.34',
        'recognition_date' => today(),
    ]);

    $csv = app(TaxRegisterService::class)->toCsv(today()->subDay(), today()->addDay());

    expect($csv)->toContain('tax_date,direction,tax_type')
        ->and($csv)->toContain('coverage_tax')
        ->and($csv)->toContain((string) $entry->getKey());
});
