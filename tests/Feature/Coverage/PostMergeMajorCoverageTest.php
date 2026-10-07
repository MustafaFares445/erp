<?php

declare(strict_types=1);

use App\Enums\OrderAvailabilityState;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\VisitsCalendar;
use App\Filament\Resources\Visits\Schemas\VisitScheduleForm;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Order;
use App\Models\PlanTask;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesPlan;
use App\Models\SalesProcurementRequirement;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyEntitlement;
use App\Services\Employees\VisitTimelineService;
use App\Services\Inventory\InventoryLotTimelineService;
use App\Services\Purchasing\ReplenishmentPurchaseOrderDraftService;
use App\Services\Sales\OrderAvailabilityService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function postMergeAction(ViewSerializedInventoryUnit $page, string $name): mixed
{
    $actions = (new ReflectionMethod(ViewSerializedInventoryUnit::class, 'getHeaderActions'))->invoke($page);

    foreach ($actions as $action) {
        if ($action->getName() === $name) {
            return $action;
        }
    }

    throw new LogicException("Missing action {$name}");
}

it('covers visit calendar day week and invalid-mode branches', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');

    try {
        $page = (new ReflectionClass(VisitsCalendar::class))->newInstanceWithoutConstructor();
        $page->mode = 'invalid';
        $page->date = null;
        $page->mount();

        expect($page->mode)->toBe('month')
            ->and($page->date)->toBe('2026-10-06');

        $range = new ReflectionMethod(VisitsCalendar::class, 'range');
        $anchor = Carbon::parse('2026-10-07');

        $page->mode = 'day';
        [$start, $end, $days, $title, $previous, $next] = $range->invoke($page, $anchor);
        expect($start->toDateString())->toBe('2026-10-07')
            ->and($end->toDateString())->toBe('2026-10-07')
            ->and($days)->toHaveCount(1)
            ->and($title)->not->toBe('')
            ->and($previous->toDateString())->toBe('2026-10-06')
            ->and($next->toDateString())->toBe('2026-10-08');

        $page->mode = 'week';
        [$start, $end, $days, $title, $previous, $next] = $range->invoke($page, $anchor);
        expect($start->dayOfWeekIso)->toBe(1)
            ->and($end->dayOfWeekIso)->toBe(7)
            ->and($days)->toHaveCount(7)
            ->and($title)->toContain('–')
            ->and($previous->diffInDays($anchor, false))->toBe(7.0)
            ->and($anchor->diffInDays($next, false))->toBe(7.0);
    } finally {
        Carbon::setTestNow();
    }
});

it('covers customer-owned equipment visit form options', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $owned = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
        'serial_number' => 'SER-COVERAGE-OWNED',
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
        'serial_number' => 'SER-COVERAGE-OTHER',
    ]);

    $schema = VisitScheduleForm::configure(Schema::make(app(CreateVisit::class)));
    $field = collect($schema->getFlatComponents(withHidden: true))
        ->first(static fn (mixed $component): bool => $component instanceof Select
            && $component->getName() === 'serialized_inventory_unit_id');

    expect($field)->toBeInstanceOf(Select::class);

    $options = (new ReflectionProperty($field, 'options'))->getValue($field);
    expect($options)->toBeInstanceOf(Closure::class);

    $invalid = Mockery::mock(Get::class);
    $invalid->shouldReceive('__invoke')->with('customer_id')->andReturn('not-numeric');
    expect($options($invalid))->toBe([]);

    $valid = Mockery::mock(Get::class);
    $valid->shouldReceive('__invoke')->with('customer_id')->andReturn($customer->getKey());
    $resolved = $options($valid);

    expect($resolved)->toHaveKey($owned->getKey())
        ->and($resolved[$owned->getKey()])->toContain('SER-COVERAGE-OWNED')
        ->and(implode(' ', $resolved))->not->toContain('SER-COVERAGE-OTHER');
});

it('covers create-visit domain rejection notification path', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->getKey()]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $this->actingAs($employee->user)->withSession([]);

    $page = (new ReflectionClass(CreateVisit::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CreateVisit::class, 'handleRecordCreation');

    expect(fn () => $method->invoke($page, [
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => '2026-10-20 10:00:00',
        'scheduled_end_at' => '2026-10-20 10:00:00',
        'override_conflict' => false,
        'override_reason' => null,
    ]))->toThrow(Halt::class);
});

it('covers visit timeline follow-up and context formatting branches', function (): void {
    $visit = CustomerVisit::factory()->create();
    $followUp = PlanTask::factory()->create([
        'sales_plan_id' => $visit->planTask->sales_plan_id,
        'customer_id' => $visit->customer_id,
        'source_visit_id' => $visit->getKey(),
        'title' => 'Coverage follow-up timeline',
    ]);

    $timeline = app(VisitTimelineService::class)->forVisit($visit->refresh());

    expect(collect($timeline)->pluck('action'))->toContain('Follow-up task created')
        ->and(collect($timeline)->pluck('context'))->toContain('Coverage follow-up timeline')
        ->and($followUp->exists)->toBeTrue();

    $context = new ReflectionMethod(VisitTimelineService::class, 'context');

    expect($context->invoke(app(VisitTimelineService::class), ['from' => 'draft', 'to' => 'active']))
        ->toBe('draft → active')
        ->and($context->invoke(app(VisitTimelineService::class), ['source_channel' => 'dashboard']))
        ->toBe('Source: dashboard')
        ->and($context->invoke(app(VisitTimelineService::class), []))
        ->toBe('');
});

it('covers remaining sales order availability states and lazy confirmations', function (): void {
    $service = app(OrderAvailabilityService::class);
    $order = Order::factory()->make(['status' => OrderStatus::Released]);

    $open = new SalesProcurementRequirement([
        'required_base_quantity' => '10.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'purchasing',
        'purchase_order_id' => 1,
    ]);
    $plainPo = PurchaseOrder::factory()->make([
        'status' => PurchaseOrderStatus::Accepted,
        'sent_at' => null,
        'supplier_confirmation_required' => false,
    ]);
    $open->setRelation('purchaseOrder', $plainPo);

    $zeroFacts = [
        'procurement_outstanding' => 10.0,
        'planned' => 0.0,
        'ready' => 0.0,
        'dispatched' => 0.0,
        'arrived' => 0.0,
        'remaining' => 10.0,
    ];

    expect($service->resolve($order, new EloquentCollection([$open]), $zeroFacts))
        ->toBe(OrderAvailabilityState::AwaitingPurchase)
        ->and($service->resolve($order, new EloquentCollection, [
            ...$zeroFacts,
            'procurement_outstanding' => 0.0,
            'dispatched' => 3.0,
            'arrived' => 1.0,
            'remaining' => 0.0,
        ]))->toBe(OrderAvailabilityState::InTransit)
        ->and($service->resolve($order, new EloquentCollection, [
            ...$zeroFacts,
            'procurement_outstanding' => 0.0,
            'planned' => 2.0,
            'remaining' => 5.0,
        ]))->toBe(OrderAvailabilityState::PartiallyAvailable);

    $persistedPo = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Accepted,
        'sent_at' => now(),
        'supplier_confirmation_required' => true,
    ]);
    $lazy = new SalesProcurementRequirement([
        'required_base_quantity' => '2.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'purchasing',
        'purchase_order_id' => $persistedPo->getKey(),
    ]);
    $lazy->setRelation('purchaseOrder', $persistedPo->withoutRelations());

    expect($service->resolve($order, new EloquentCollection([$lazy]), [
        ...$zeroFacts,
        'procurement_outstanding' => 2.0,
        'remaining' => 2.0,
    ]))->toBe(OrderAvailabilityState::AwaitingSupplierConfirmation);
});

it('covers inventory lot timeline operation return manual and counterparty branches', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Timeline Customer']);
    $supplier = Supplier::factory()->create(['name' => 'Timeline Supplier']);
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->for($warehouse)->create();

    $delivery = InventoryOperation::factory()->delivery()->create([
        'customer_id' => $customer->getKey(),
        'operation_number' => null,
    ]);
    $receipt = InventoryOperation::factory()->receipt()->create([
        'supplier_id' => $supplier->getKey(),
        'operation_number' => 'OP-TIMELINE',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'customer_id' => $customer->getKey(),
        'return_number' => 'RET-TIMELINE',
    ]);

    foreach ([
        ['inventory_operation', $delivery->getKey(), 'delivery'],
        ['inventory_operation', $receipt->getKey(), 'receipt'],
        ['inventory_return', $return->getKey(), 'return'],
        [null, null, 'manual'],
        ['legacy_source', 44, 'legacy'],
    ] as [$type, $id, $note]) {
        InventoryMovement::factory()->create([
            'inventory_lot_id' => $lot->getKey(),
            'product_variant_id' => $lot->product_variant_id,
            'warehouse_id' => $warehouse->getKey(),
            'source_type' => $type,
            'source_id' => $id,
            'base_quantity_delta' => '1.000000',
            'transaction_quantity' => '1.000000',
            'transaction_unit_id' => $lot->productVariant->unit_id,
            'stock_condition_from' => StockCondition::Saleable,
            'stock_condition_to' => StockCondition::Damaged,
            'notes' => $note,
        ]);
    }

    $service = app(InventoryLotTimelineService::class);
    $events = $service->events($lot->refresh());

    expect($events)->toHaveCount(5)
        ->and(collect($events)->pluck('source'))->toContain('Manual', 'legacy_source #44', 'Inventory Return RET-TIMELINE')
        ->and(collect($events)->pluck('counterparty'))->toContain('Timeline Customer', 'Timeline Supplier');

    $counterparty = new ReflectionMethod(InventoryLotTimelineService::class, 'counterpartyLabel');
    $supplierReturn = InventoryReturn::factory()->supplier()->create(['supplier_id' => $supplier->getKey()]);

    expect($counterparty->invoke($service, null, $supplierReturn))->toBe('Timeline Supplier')
        ->and($counterparty->invoke($service, null, null))->toBeNull();

    $source = new ReflectionMethod(InventoryLotTimelineService::class, 'sourceLabel');
    $movement = new InventoryMovement;
    $movement->forceFill(['source_type' => null, 'source_id' => null]);
    expect($source->invoke($service, $movement, null, null))->toBe('Manual');
});

it('covers replenishment purchase draft validation helpers', function (): void {
    $service = app(ReplenishmentPurchaseOrderDraftService::class);
    $actor = User::factory()->admin()->create();

    expect(fn () => $service->createDrafts($actor, [999999999]))
        ->toThrow(ValidationException::class, 'no longer active');

    $integerKey = new ReflectionMethod(ReplenishmentPurchaseOrderDraftService::class, 'integerModelKey');
    expect(fn () => $integerKey->invoke(null, new ReplenishmentRequirement, 'replenishment requirement'))
        ->toThrow(ValidationException::class, 'integer identifier');

    $variant = ProductVariant::factory()->create();
    $reference = new SupplierProductReference([
        'product_variant_id' => $variant->getKey(),
        'purchase_unit_id' => 999999999,
    ]);
    $purchaseConfiguration = new ReflectionMethod(ReplenishmentPurchaseOrderDraftService::class, 'purchaseConfiguration');

    expect(fn () => $purchaseConfiguration->invoke($service, $variant, $reference))
        ->toThrow(ValidationException::class, 'no longer active');

    $variant->variantUnits()->update(['is_purchase' => false]);
    $reference->purchase_unit_id = null;

    expect(fn () => $purchaseConfiguration->invoke($service, $variant->refresh(), $reference))
        ->toThrow(ValidationException::class, 'no active purchase UoM');

    $rounded = new ReflectionMethod(ReplenishmentPurchaseOrderDraftService::class, 'roundedPurchaseQuantity');
    $invalidConfiguration = new ProductVariantUnit([
        'factor_to_base' => '0.000000',
        'rounding_increment' => '1.000000',
    ]);
    $invalidConfiguration->setRelation('unit', new Unit(['precision' => 2]));

    expect(fn () => $rounded->invoke(
        $service,
        '10.000000',
        $invalidConfiguration,
        new SupplierProductReference,
    ))->toThrow(ValidationException::class, 'must be positive');

    $preciseConfiguration = new ProductVariantUnit([
        'factor_to_base' => '1.000000',
        'rounding_increment' => '1.000000',
    ]);
    $preciseConfiguration->setRelation('unit', new Unit(['precision' => 8]));

    expect($rounded->invoke(
        $service,
        '1.250000',
        $preciseConfiguration,
        new SupplierProductReference,
    ))->toBe('2.000000');
});

it('covers serialized warranty correction fill action and handled rejection', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $unit = SerializedInventoryUnit::factory()->create();
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today(),
        'expires_on' => today()->addYear(),
    ]);

    $page = Livewire::actingAs($actor)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getKey()])
        ->instance();
    $correct = postMergeAction($page, 'correctWarrantyDates');

    $mount = $correct->getMountUsing();
    $captured = (new ReflectionFunction($mount))->getStaticVariables();
    $fill = $captured['data'] ?? null;

    expect($fill)->toBeInstanceOf(Closure::class);
    $filled = $fill($unit->refresh());

    expect($filled['starts_on'])->toBe($entitlement->starts_on?->toDateString())
        ->and($filled['expires_on'])->toBe($entitlement->expires_on?->toDateString());

    $action = $correct->getActionFunction();
    expect($action)->toBeInstanceOf(Closure::class);

    $action($unit->refresh(), [
        'starts_on' => today()->addDay()->toDateString(),
        'expires_on' => today()->addYear()->addDay()->toDateString(),
        'reason' => 'Coverage correction',
    ]);

    expect($entitlement->refresh()->starts_on?->toDateString())->toBe(today()->addDay()->toDateString());

    $entitlement->forceFill(['state' => WarrantyEntitlementState::Cancelled])->saveQuietly();
    $action($unit->refresh(), [
        'starts_on' => today()->toDateString(),
        'expires_on' => today()->addYear()->toDateString(),
        'reason' => 'Handled inactive correction',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Cancelled)
        ->and(fn () => $action($unit->refresh(), ['starts_on' => null, 'expires_on' => null, 'reason' => null]))
        ->toThrow(LogicException::class, 'correction data is invalid');
});
