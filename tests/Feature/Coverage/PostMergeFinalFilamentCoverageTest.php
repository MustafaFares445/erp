<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Resources\InventoryLots\Pages\ListInventoryLots;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\InventoryLots\Schemas\InventoryLotInfolist;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Visits\Actions\VisitManagementActions;
use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Filament\Resources\Visits\Schemas\VisitInfolist;
use App\Filament\Widgets\InventoryExpiringLots;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryReservation;
use App\Models\MaintenanceRecord;
use App\Models\Manufacturer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function postMergeFinalUiFind(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (is_object($component) && method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        if (! is_object($component)) {
            continue;
        }

        try {
            if (method_exists($component, 'getDefaultChildComponents')) {
                $found = postMergeFinalUiFind($component->getDefaultChildComponents(), $name);
                if ($found !== null) {
                    return $found;
                }
            }
        } catch (Throwable) {
        }

        try {
            if (method_exists($component, 'getChildSchema')) {
                $child = $component->getChildSchema();
                if ($child !== null) {
                    $found = postMergeFinalUiFind($child->getFlatComponents(withHidden: true), $name);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    return null;
}

it('covers the manufacturer catalog tab header table form and empty state', function (): void {
    Gate::before(static fn (): bool => true);
    $this->actingAs(User::factory()->admin()->create());

    $page = app(CatalogSetup::class);
    $page->tab = 'manufacturers';

    $actions = (new ReflectionMethod(CatalogSetup::class, 'getHeaderActions'))->invoke($page);
    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(CreateAction::class);

    $actionSchema = $actions[0]->getSchema(Schema::make($page));
    expect($actionSchema?->getFlatComponents(withHidden: true))->not->toBeEmpty();

    $table = $page->table(Table::make($page));
    expect($table->getQuery()->getModel())->toBeInstanceOf(Manufacturer::class);

    $description = (new ReflectionMethod(CatalogSetup::class, 'emptyStateDescription'))->invoke($page);
    expect($description)->toContain('manufacturers');
});

it('covers product brand filtering by manufacturer', function (): void {
    $manufacturer = Manufacturer::factory()->create();
    $otherManufacturer = Manufacturer::factory()->create();
    $global = Brand::factory()->create(['manufacturer_id' => null, 'is_active' => true]);
    $matching = Brand::factory()->create(['manufacturer_id' => $manufacturer->getKey(), 'is_active' => true]);
    $other = Brand::factory()->create(['manufacturer_id' => $otherManufacturer->getKey(), 'is_active' => true]);

    $schema = ProductForm::configure(Schema::make(app(ManageProducts::class))->model(Product::class));
    $field = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'brand_id');

    expect($field)->toBeInstanceOf(Select::class);
    $options = (new ReflectionProperty($field, 'options'))->getValue($field);

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('manufacturer_id')->andReturn($manufacturer->getKey());

    $resolved = $options($get);
    expect($resolved)->toHaveKey($global->getKey())
        ->and($resolved)->toHaveKey($matching->getKey())
        ->and($resolved)->not->toHaveKey($other->getKey());
});

it('covers purchase-order supplier reactive missing and default branches', function (): void {
    (new PurchasePermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($actor);

    $test = Livewire::actingAs($actor)->test(CreatePurchaseOrder::class);
    $schema = $test->instance()->getSchema('form');
    $supplierSelect = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'supplier_id');

    expect($supplierSelect)->toBeInstanceOf(Select::class);

    /** @var array<int, Closure> $callbacks */
    $callbacks = (new ReflectionProperty($supplierSelect, 'afterStateUpdated'))->getValue($supplierSelect);
    expect($callbacks)->not->toBeEmpty();

    $missingSet = Mockery::mock(Set::class);
    $missingSet->shouldReceive('__invoke')->once()->with('lines', []);
    $callbacks[0]($missingSet, 999999999, null);

    $supplier = Supplier::factory()->create([
        'default_currency_code' => 'AED',
        'default_lead_time_days' => 5,
        'payment_term_id' => null,
        'is_active' => true,
    ]);
    $validSet = Mockery::mock(Set::class);
    $validSet->shouldReceive('__invoke')->once()->with('lines', []);
    $validSet->shouldReceive('__invoke')->once()->with('currency_code', 'AED');
    $validSet->shouldReceive('__invoke')->once()->with('payment_term_id', null);
    $validSet->shouldReceive('__invoke')->once()->with('expected_at', today()->addDays(5)->toDateString());

    $callbacks[0]($validSet, $supplier->getKey(), null);
});

it('prefills supplier currency and expected date from the create-page query', function (): void {
    (new PurchasePermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::PurchasingManager->value);
    $supplier = Supplier::factory()->create([
        'default_currency_code' => 'AED',
        'default_lead_time_days' => 4,
        'is_active' => true,
    ]);

    $component = Livewire::withQueryParams(['supplier_id' => (string) $supplier->getKey()])
        ->actingAs($actor)
        ->test(CreatePurchaseOrder::class)
        ->assertSuccessful();

    $state = $component->instance()->getSchema('form')->getRawState();

    expect((string) ($state['currency_code'] ?? ''))->toBe('AED')
        ->and((string) ($state['expected_at'] ?? ''))->toBe(today()->addDays(4)->toDateString());
});

it('covers expiry bucket display variants in infolist and lots table', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $list = Livewire::actingAs($actor)->test(ListInventoryLots::class)->instance();
    $table = $list->getTable();
    $column = $table->getColumn('expiry_bucket');

    expect($column)->toBeInstanceOf(TextColumn::class);

    $cases = [
        today()->subDay(),
        today()->addDays(45),
        today()->addDays(75),
        today()->addDays(120),
    ];

    foreach ($cases as $date) {
        $lot = InventoryLot::factory()->create(['expires_at' => $date]);

        $schema = InventoryLotInfolist::configure(Schema::make(app(ViewInventoryLot::class))->record($lot));
        $entry = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'expiry_bucket');
        expect($entry)->toBeInstanceOf(TextEntry::class)
            ->and($entry->getState())->toBeString();

        $column->record($lot);
        expect($column->getState())->toBeString();
    }
});

it('covers inventory expiring-lot color windows', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $widget = Livewire::actingAs($actor)->test(InventoryExpiringLots::class)->instance();
    $column = $widget->getTable()->getColumn('days_remaining');

    expect($column)->toBeInstanceOf(TextColumn::class);

    foreach ([
        [today()->subDay(), 'danger'],
        [today()->addDays(15), 'danger'],
        [today()->addDays(45), 'warning'],
        [today()->addDays(75), 'info'],
    ] as [$date, $expected]) {
        $lot = InventoryLot::factory()->create(['expires_at' => $date]);
        $balance = new InventoryLotBalance;
        $balance->forceFill(['id' => 424242]);
        $balance->setRelation('lot', $lot);
        $column->record($balance);

        expect($column->getColor($column->getState()))->toBe($expected);
    }
});

it('covers sales-order reservation link generation', function (): void {
    $order = Order::factory()->create();
    $reservation = InventoryReservation::factory()->create(['sales_order_id' => $order->getKey()])
        ->load('salesOrder');

    $schema = InventoryReservationResource::infolist(Schema::make(app(ViewInventoryReservation::class))->record($reservation));
    $entry = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'salesOrder.order_number');

    expect($entry)->toBeInstanceOf(TextEntry::class)
        ->and($entry->getUrl())->toContain((string) $order->getKey());
});

it('covers visit infolist recorded coordinates and detected product labels', function (): void {
    $visit = CustomerVisit::factory()->create([
        'check_in_latitude' => 25.2048,
        'check_in_longitude' => 55.2708,
    ]);

    $schema = VisitInfolist::configure(Schema::make(app(ViewVisit::class))->record($visit));
    $coordinates = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'check_in_coordinates');

    expect($coordinates)->toBeInstanceOf(TextEntry::class)
        ->and($coordinates->getState())->toContain('25.2048');

    $variant = ProductVariant::factory()->create();
    $variantOpportunity = new SalesOpportunity;
    $variantOpportunity->setRelation('detectedProductVariant', $variant);
    $variantOpportunity->setRelation('detectedProduct', null);

    $label = new ReflectionMethod(VisitInfolist::class, 'detectedProductLabel');
    expect($label->invoke(null, $variantOpportunity))->toBe($variant->sku);

    $product = Product::factory()->create();
    $productOpportunity = new SalesOpportunity;
    $productOpportunity->setRelation('detectedProductVariant', null);
    $productOpportunity->setRelation('detectedProduct', $product);

    expect($label->invoke(null, $productOpportunity))->toBe($product->name);
});

it('covers successful visit rescheduling and valid string helper', function (): void {
    $visit = CustomerVisit::factory()->create([
        'scheduled_start_at' => now()->addDays(3)->setTime(10, 0),
        'scheduled_end_at' => now()->addDays(3)->setTime(11, 0),
    ]);

    $string = new ReflectionMethod(VisitManagementActions::class, 'string');
    expect($string->invoke(null, ['scheduled_start_at' => '2026-10-20 10:00:00'], 'scheduled_start_at'))
        ->toBe('2026-10-20 10:00:00');

    $action = VisitManagementActions::reschedule();
    $callback = $action->getActionFunction();

    expect($callback)->toBeInstanceOf(Closure::class);

    $callback($visit, [
        'scheduled_start_at' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(),
        'scheduled_end_at' => now()->addDays(4)->setTime(11, 0)->toDateTimeString(),
        'override_conflict' => false,
        'override_reason' => null,
    ]);

    expect($visit->refresh()->scheduled_start_at?->toDateString())->toBe(now()->addDays(4)->toDateString());
});

it('normalizes a non-array quotation lines payload to an empty line set', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $quotation = Quotation::factory()->create();
    $page = (new ReflectionClass(EditQuotation::class))->newInstanceWithoutConstructor();
    $update = new ReflectionMethod(EditQuotation::class, 'handleRecordUpdate');

    $result = $update->invoke($page, $quotation, [
        'customer_id' => $quotation->customer_id,
        'employee_id' => $quotation->employee_id,
        'payment_term_id' => $quotation->payment_term_id,
        'issue_date' => $quotation->issue_date->toDateString(),
        'expires_at' => $quotation->expires_at?->toDateString(),
        'lines' => 'not-an-array',
    ]);

    expect($result)->toBeInstanceOf(Quotation::class)
        ->and($result->lines()->count())->toBe(0);
});

it('covers visit equipment warranty and maintenance display branches', function (): void {
    $customer = CustomerProfile::factory()->create();

    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
        'warranty_expires_on' => today()->addMonth(),
    ]);
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addMonth(),
    ]);
    MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    $visit = CustomerVisit::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $schema = VisitInfolist::configure(Schema::make(app(ViewVisit::class))->record($visit));
    $warranty = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'equipment_warranty_state');
    $maintenance = postMergeFinalUiFind($schema->getFlatComponents(withHidden: true), 'equipment_recent_maintenance');

    expect($warranty)->toBeInstanceOf(TextEntry::class)
        ->and($warranty->getState())->toBeString()
        ->and($maintenance)->toBeInstanceOf(TextEntry::class)
        ->and($maintenance->getState())->toContain('#');

    foreach ([today()->addDay(), today()->subDay()] as $expiry) {
        $legacyUnit = SerializedInventoryUnit::factory()->create([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_type' => 'customer',
            'custody_reference_id' => $customer->getKey(),
            'warranty_expires_on' => $expiry,
        ]);
        $legacyVisit = CustomerVisit::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $legacyUnit->getKey(),
        ]);
        $legacySchema = VisitInfolist::configure(Schema::make(app(ViewVisit::class))->record($legacyVisit));
        $legacyWarranty = postMergeFinalUiFind(
            $legacySchema->getFlatComponents(withHidden: true),
            'equipment_warranty_state',
        );

        expect($legacyWarranty)->toBeInstanceOf(TextEntry::class)
            ->and($legacyWarranty->getState())->toBeString();
    }
});
