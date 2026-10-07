<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Enums\VisitStatus;
use App\Enums\WarrantyEntitlementState;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Models\WarrantyEntitlement;
use App\Services\Employees\FollowUpCreationService;
use App\Services\Employees\SalesPlanService;
use App\Services\Inventory\InventoryLotTimelineService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Inventory\ReplenishmentRecommendationService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\SalesDemandProcurementService;
use App\Services\Purchasing\SupplierConfirmationService;
use App\Services\Sales\PriceProvenanceService;
use App\Services\Support\WarrantyEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

it('covers all customer visit persistence invariants', function (): void {
    $base = CustomerVisit::factory()->create();

    $checkoutBefore = $base->replicate();
    $checkoutBefore->checked_in_at = now();
    $checkoutBefore->checked_out_at = now()->subMinute();
    expect(fn () => $checkoutBefore->save())->toThrow(DomainException::class, 'Check-out cannot occur before check-in');

    $completed = $base->replicate();
    $completed->status = VisitStatus::Completed;
    $completed->outcome_code = null;
    expect(fn () => $completed->save())->toThrow(DomainException::class, 'completed visit requires');

    $followUp = $base->replicate();
    $followUp->follow_up_required = true;
    $followUp->follow_up_date = null;
    expect(fn () => $followUp->save())->toThrow(DomainException::class, 'follow-up date is required');

    $override = $base->replicate();
    $override->schedule_overridden_at = now();
    $override->schedule_override_reason = '   ';
    expect(fn () => $override->save())->toThrow(DomainException::class, 'override reason is required');
});

it('resolves opportunity customer context through its source visit', function (): void {
    $visit = CustomerVisit::factory()->create();
    $opportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => null,
        'lead_id' => null,
        'source_visit_id' => $visit->getKey(),
    ]);

    expect($opportunity->resolvedCustomer()?->is($visit->customer))->toBeTrue();
});

it('rejects follow-up creation when the visit is detached from a monthly plan task', function (): void {
    $visit = CustomerVisit::factory()->create([
        'follow_up_required' => true,
        'follow_up_date' => today()->addDay(),
    ]);
    $visit->setRelation('planTask', null);

    expect(fn () => app(FollowUpCreationService::class)->createForVisit($visit))
        ->toThrow(LogicException::class, 'monthly plan task');
});

it('logs the published plan material-change path', function (): void {
    $plan = SalesPlan::factory()->published()->create();
    $employee = EmployeeProfile::factory()->create();

    $updated = app(SalesPlanService::class)->update($plan, ['employee_id' => $employee->getKey()]);

    expect($updated->employee_id)->toBe($employee->getKey());
});

it('rejects inventory timeline rows that lost their persistence timestamp', function (): void {
    $lot = InventoryLot::factory()->create();
    $movement = InventoryMovement::factory()->create([
        'inventory_lot_id' => $lot->getKey(),
        'product_variant_id' => $lot->product_variant_id,
    ]);
    DB::table('inventory_movements')->where('id', $movement->getKey())->update(['created_at' => null]);

    expect(fn () => app(InventoryLotTimelineService::class)->events($lot))
        ->toThrow(LogicException::class, 'creation timestamp');
});

it('covers critical warning and notice expiry report windows', function (): void {
    $service = app(InventoryReportService::class);

    foreach (['critical', 'warning', 'notice'] as $state) {
        $query = $service->query(InventoryReportType::ExpiryLots, ['expiry_state' => $state]);
        expect($query->toSql())->toContain('expires_at');
    }
});

it('rejects a lot allocation for a variant that does not track lots', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();
    $method = new ReflectionMethod(InventoryReservationService::class, 'validatedLotAllocation');

    expect(fn () => $method->invoke(
        app(InventoryReservationService::class),
        1,
        (int) $variant->getKey(),
        (int) $warehouse->getKey(),
        '1.000000',
        null,
        null,
    ))->toThrow(DomainException::class);
});

it('covers price-list skip branches for inactive mismatched and itemless lists', function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'EUR'],
        ['name' => 'Euro', 'is_active' => true, 'is_default' => false],
    );

    $variant = ProductVariant::factory()->create(['base_price' => '100.00']);
    $resolver = app(PriceResolver::class);

    $inactive = PriceList::query()->create(['name' => 'Inactive coverage', 'currency_code' => 'AED', 'is_active' => false]);
    $profileA = CustomerProfile::factory()->create();
    DB::table('customer_profiles')->where('id', $profileA->getKey())->update(['default_price_list_id' => $inactive->getKey()]);
    expect($resolver->resolve($variant, $profileA->user)->amount)->toBe(100.0);

    $mismatch = PriceList::query()->create(['name' => 'EUR coverage', 'currency_code' => 'EUR', 'is_active' => true]);
    $profileB = CustomerProfile::factory()->create();
    DB::table('customer_profiles')->where('id', $profileB->getKey())->update([
        'default_price_list_id' => $mismatch->getKey(),
        'default_currency_code' => 'AED',
    ]);
    expect($resolver->resolve($variant, $profileB->user)->amount)->toBe(100.0);

    $itemless = PriceList::query()->create(['name' => 'Itemless coverage', 'currency_code' => 'AED', 'is_active' => true]);
    $profileC = CustomerProfile::factory()->create();
    DB::table('customer_profiles')->where('id', $profileC->getKey())->update([
        'default_price_list_id' => $itemless->getKey(),
        'default_currency_code' => 'AED',
    ]);
    expect($resolver->resolve($variant, $profileC->user)->amount)->toBe(100.0);
});

it('covers invalid optional UOM string validation', function (): void {
    $method = new ReflectionMethod(ProductVariantUomService::class, 'optionalString');

    expect(fn () => $method->invoke(app(ProductVariantUomService::class), 123, 'barcode', 10))
        ->toThrow(ValidationException::class);

    expect(fn () => $method->invoke(app(ProductVariantUomService::class), str_repeat('x', 11), 'barcode', 10))
        ->toThrow(ValidationException::class);
});

it('returns zero replenishment recommendation above the minimum', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 30,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 30,
    ]);
    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 5,
        'max_quantity' => 20,
        'is_active' => true,
    ]);

    expect(app(ReplenishmentRecommendationService::class)->recommendation($policy)->suggestedBaseQuantity)
        ->toBe(0.0);
});

it('validates FEFO override reason type and length in fulfillment assignments', function (): void {
    $method = new ReflectionMethod(OrderFulfillmentService::class, 'lotAssignments');
    $service = app(OrderFulfillmentService::class);
    $base = [[
        'warehouse_id' => 1,
        'assignments' => [[
            'product_variant_id' => 1,
            'inventory_lot_id' => 1,
            'quantity' => 1,
            'fefo_override_reason' => 123,
        ]],
    ]];

    expect(fn () => $method->invoke($service, $base))
        ->toThrow(ValidationException::class, 'must be text');

    $base[0]['assignments'][0]['fefo_override_reason'] = str_repeat('x', 256);
    expect(fn () => $method->invoke($service, $base))
        ->toThrow(ValidationException::class, 'may not exceed');
});

it('rejects nonnumeric supplier MOQ quantity input', function (): void {
    $variant = ProductVariant::factory()->create();
    $reference = SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'minimum_order_quantity' => '5.000000',
    ]);
    $method = new ReflectionMethod(PurchaseOrderService::class, 'assertSupplierReferenceTerms');

    expect(fn () => $method->invoke(
        app(PurchaseOrderService::class),
        $reference,
        $variant,
        (int) $variant->unit_id,
        'not-a-number',
    ))->toThrow(DomainException::class, 'greater than zero');
});

it('covers explicit purchase UOM MOQ and increment rounding for Sales procurement', function (): void {
    $variant = ProductVariant::factory()->create();
    $unit = $variant->variantUnits()->firstOrFail();
    $reference = SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'purchase_unit_id' => $unit->unit_id,
        'minimum_order_quantity' => '2.500000',
    ]);
    $service = app(SalesDemandProcurementService::class);

    $purchaseUnit = new ReflectionMethod(SalesDemandProcurementService::class, 'purchaseUnit');
    expect($purchaseUnit->invoke($service, $variant->load('variantUnits'), $reference)->getKey())
        ->toBe($unit->getKey());

    $round = new ReflectionMethod(SalesDemandProcurementService::class, 'purchaseQuantity');
    $configured = new ProductVariantUnit([
        'factor_to_base' => '1.000000',
        'rounding_increment' => '1.000000',
    ]);

    expect($round->invoke($service, '1.200000', $configured, $reference))->toBe('3.000000');
});

it('rejects an overlong supplier confirmation reference', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $service = app(SupplierConfirmationService::class);
    $order = PurchaseOrder::factory()->sent()->create([
        'status' => PurchaseOrderStatus::Accepted,
        'supplier_confirmation_required' => true,
    ]);
    $order->supplier()->update(['requires_confirmation' => true]);
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
        'unit_cost' => '10.00',
    ]);
    $confirmation = $service->recordPurchaseOrder($actor, $order->refresh());

    expect(fn () => $service->respond(
        $actor,
        $confirmation,
        SupplierConfirmationStatus::Confirmed,
        CarbonImmutable::now()->addWeek(),
        'Confirmed',
        [],
        str_repeat('R', 151),
    ))->toThrow(ValidationException::class, 'may not exceed 150');
});

it('rejects nonpositive price provenance conversion factors', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn () => app(PriceProvenanceService::class)->forManualPrice($variant, null, 10.0, 0.0))
        ->toThrow(DomainException::class, 'positive unit conversion factor');
});

it('detects a warranty entitlement that became inactive after the stale outer check', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $entitlement = WarrantyEntitlement::factory()->create(['state' => WarrantyEntitlementState::Active]);

    DB::table('warranty_entitlements')->where('id', $entitlement->getKey())->update([
        'state' => WarrantyEntitlementState::Cancelled->value,
    ]);

    expect(fn () => app(WarrantyEntitlementService::class)->correctDates(
        $entitlement,
        today(),
        today()->addYear(),
        $actor,
        'Coverage stale-state check',
    ))->toThrow(DomainException::class, 'Only an active warranty entitlement');
});
