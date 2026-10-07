<?php

declare(strict_types=1);

use App\Enums\ProductOperationalProfile;
use App\Enums\ProductType;
use App\Enums\TrackingMode;
use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Events\FollowUpTaskCreated;
use App\Events\VisitAssigned;
use App\Events\VisitRescheduled;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\CustomerGroup;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\InventoryStock;
use App\Models\Manufacturer;
use App\Models\PlanTask;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesPlan;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Observers\ProductVariantObserver;
use App\Services\Employees\Exceptions\VisitScheduleConflict;
use App\Services\Employees\VisitLifecycleService;
use App\Services\Employees\VisitSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    Event::fake([VisitAssigned::class, VisitRescheduled::class, FollowUpTaskCreated::class]);
});

function visitScheduleFixture(): array
{
    $employee = EmployeeProfile::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->getKey()]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    return [$employee, $customer, $plan, $task];
}

it('covers visit scheduling, conflicts, overrides and rescheduling', function (): void {
    [$employee, $customer, , $task] = visitScheduleFixture();
    $this->actingAs($employee->user);

    $service = app(VisitSchedulingService::class);
    $start = Carbon::parse('2026-10-20 09:00:00');
    $end = $start->copy()->addHour();

    $visit = $service->schedule([
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $end,
        'visit_type' => 'Customer meeting',
    ]);

    expect($visit->reference)->toStartWith('VIS-')
        ->and($visit->status)->toBe(VisitStatus::Scheduled)
        ->and($service->conflictsFor((int) $employee->getKey(), $start, $end))->toHaveCount(1)
        ->and($service->conflictsFor((int) $employee->getKey(), $start, $end, (int) $visit->getKey()))->toBeEmpty();

    $payload = [
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => $start->copy()->addMinutes(15),
        'scheduled_end_at' => $end->copy()->addMinutes(15),
    ];

    expect(fn () => $service->schedule($payload))
        ->toThrow(VisitScheduleConflict::class)
        ->and(fn () => $service->schedule($payload, true, '   '))
        ->toThrow(DomainException::class, 'override reason');

    $override = $service->schedule($payload, true, 'Manager-approved overlap');
    expect($override->schedule_override_reason)->toBe('Manager-approved overlap')
        ->and($override->schedule_overridden_by)->toBe($employee->user_id);

    $newStart = $start->copy()->addDays(2);
    $rescheduled = $service->reschedule($visit->refresh(), $newStart, $newStart->copy()->addHours(2));

    expect($rescheduled->scheduled_start_at?->equalTo($newStart))->toBeTrue()
        ->and($rescheduled->planned_at?->equalTo($newStart))->toBeTrue();

    $rescheduled->forceFill(['status' => VisitStatus::Completed])->saveQuietly();

    expect(fn () => $service->reschedule(
        $rescheduled,
        $newStart->copy()->addDay(),
        $newStart->copy()->addDay()->addHour(),
    ))->toThrow(DomainException::class, 'terminal visit');
});

it('covers visit scheduling validation branches', function (): void {
    [$employee, $customer, , $task] = visitScheduleFixture();
    $service = app(VisitSchedulingService::class);
    $start = Carbon::parse('2026-10-21 09:00:00');

    expect(fn () => $service->schedule([
        'employee_id' => 'invalid',
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $start->copy()->addHour(),
    ]))->toThrow(LogicException::class, 'employee_id');

    expect(fn () => $service->schedule([
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => [],
        'scheduled_end_at' => $start->copy()->addHour(),
    ]))->toThrow(LogicException::class, 'scheduled_start_at');

    expect(fn () => $service->schedule([
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $start,
    ]))->toThrow(DomainException::class, 'scheduled end');

    $otherEmployee = EmployeeProfile::factory()->create();
    expect(fn () => $service->schedule([
        'employee_id' => $otherEmployee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $start->copy()->addHour(),
    ]))->toThrow(DomainException::class, 'selected employee');

    $otherCustomer = CustomerProfile::factory()->create();
    expect(fn () => $service->schedule([
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $otherCustomer->getKey(),
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $start->copy()->addHour(),
    ]))->toThrow(DomainException::class, 'task customer');
});

it('covers visit lifecycle follow-up and location edge cases', function (): void {
    [$employee, $customer, , $task] = visitScheduleFixture();
    $this->actingAs($employee->user);

    $customer->update(['latitude' => '36.2021000', 'longitude' => '37.1343000']);
    $service = app(VisitLifecycleService::class);

    $visit = CustomerVisit::factory()->create([
        'employee_id' => $employee->getKey(),
        'customer_id' => $customer->getKey(),
        'plan_task_id' => $task->getKey(),
        'status' => VisitStatus::Scheduled,
    ]);

    $visit = $service->markEnRoute($visit);
    $visit = $service->checkIn($visit, 36.3021, 37.2343, 7.5);

    expect($visit->location_warning)->toBeTrue()
        ->and($visit->location_override_reason)->toBeNull();

    $overrideVisit = CustomerVisit::factory()->create([
        'employee_id' => $employee->getKey(),
        'customer_id' => $customer->getKey(),
        'plan_task_id' => $task->getKey(),
        'status' => VisitStatus::EnRoute,
    ]);
    $overrideVisit = $service->checkIn($overrideVisit, 36.3021, 37.2343, null, 'GPS verified manually');

    expect($overrideVisit->location_warning)->toBeTrue()
        ->and($overrideVisit->location_override_reason)->toBe('GPS verified manually');

    expect(fn () => $service->complete(
        $overrideVisit->refresh(),
        VisitOutcome::FollowUpRequired,
    ))->toThrow(DomainException::class, 'follow-up date');

    $completed = $service->complete(
        $overrideVisit->refresh(),
        VisitOutcome::FollowUpRequired,
        'Needs another visit',
        false,
        now()->addDays(3),
        'Bring replacement part',
        'Customer informed',
    );

    expect($completed->follow_up_required)->toBeTrue()
        ->and($completed->followUpTask()->exists())->toBeTrue();

    $futureCheckIn = CustomerVisit::factory()->create([
        'employee_id' => $employee->getKey(),
        'customer_id' => $customer->getKey(),
        'plan_task_id' => $task->getKey(),
        'status' => VisitStatus::InProgress,
        'checked_in_at' => now()->addDay(),
    ]);

    expect(fn () => $service->complete($futureCheckIn, VisitOutcome::Successful))
        ->toThrow(DomainException::class, 'before check-in');

    $transition = new ReflectionMethod($service, 'transition');
    expect(fn () => $transition->invoke(
        $service,
        CustomerVisit::factory()->create(['status' => VisitStatus::InProgress]),
        VisitStatus::Completed,
        [],
        'coverage.completed',
    ))->toThrow(DomainException::class, 'valid visit outcome');
});

it('covers customer commercial assignment validation', function (): void {
    $inactiveList = PriceList::query()->create([
        'name' => 'Inactive',
        'currency_code' => 'AED',
        'is_active' => false,
    ]);
    $customer = CustomerProfile::factory()->create(['default_currency_code' => null]);

    $customer->default_price_list_id = $inactiveList->getKey();
    expect(fn () => $customer->save())
        ->toThrow(ValidationException::class, 'default price list must be active');

    $activeList = PriceList::query()->create([
        'name' => 'Active AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $customer = CustomerProfile::factory()->create(['default_currency_code' => null]);
    $customer->default_price_list_id = $activeList->getKey();
    $customer->save();
    expect($customer->default_currency_code)->toBe('AED');

    $customer->default_currency_code = 'USD';
    Currency::query()->updateOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'is_active' => true, 'is_default' => false],
    );
    expect(fn () => $customer->save())
        ->toThrow(ValidationException::class, 'currency must match');

    $inactiveGroup = CustomerGroup::query()->create([
        'name' => 'Inactive group',
        'code' => 'INACTIVE-GROUP',
        'is_active' => false,
    ]);
    $customer = CustomerProfile::factory()->create();
    $customer->customer_group_id = $inactiveGroup->getKey();
    expect(fn () => $customer->save())
        ->toThrow(ValidationException::class, 'customer group must be active');

    $customer = CustomerProfile::factory()->create();
    $customer->assigned_sales_employee_id = User::factory()->admin()->create()->getKey();
    expect(fn () => $customer->save())
        ->toThrow(ValidationException::class, 'employee account');
});

it('covers price-list item validation guards', function (): void {
    $list = PriceList::query()->create([
        'name' => 'Coverage AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->create();
    $other = ProductVariant::factory()->create();

    $base = [
        'price_list_id' => $list->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => null,
        'minimum_quantity' => null,
        'price' => '10.00',
        'is_active' => true,
    ];

    expect(fn () => PriceListItem::query()->create([...$base, 'price' => '-0.01']))
        ->toThrow(ValidationException::class, 'zero or greater')
        ->and(fn () => PriceListItem::query()->create([...$base, 'minimum_quantity' => '0']))
        ->toThrow(ValidationException::class, 'greater than zero')
        ->and(fn () => PriceListItem::query()->create([
            ...$base,
            'valid_from' => today()->addDay(),
            'valid_to' => today(),
        ]))->toThrow(ValidationException::class, 'on or after')
        ->and(fn () => PriceListItem::query()->create([
            ...$base,
            'product_variant_id' => $other->getKey(),
        ]))->toThrow(ValidationException::class, 'must belong');
});

it('covers product and variant observer invariants', function (): void {
    $manufacturer = Manufacturer::factory()->create();
    $otherManufacturer = Manufacturer::factory()->create();
    $brand = Brand::factory()->create(['manufacturer_id' => $manufacturer->getKey()]);

    $product = Product::factory()->create([
        'brand_id' => $brand->getKey(),
        'manufacturer_id' => null,
        'operational_profile' => null,
        'product_type' => ProductType::Machine,
    ]);

    expect($product->manufacturer_id)->toBe($manufacturer->getKey())
        ->and($product->operational_profile)->toBe(ProductOperationalProfile::Serialized);

    expect(fn () => Product::factory()->create([
        'brand_id' => $brand->getKey(),
        'manufacturer_id' => $otherManufacturer->getKey(),
    ]))->toThrow(ValidationException::class, 'does not belong');

    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'available_quantity' => 5,
    ]);

    $variant->tracking_mode = TrackingMode::Serial;
    expect(fn () => $variant->save())
        ->toThrow(ValidationException::class, 'Tracking configuration');

    $trackingOnly = new ProductVariant;
    $trackingOnly->forceFill(['tracking_mode' => TrackingMode::Serial]);
    expect($trackingOnly->trackingMode())->toBe(TrackingMode::Serial);

    $legacy = new ProductVariant;
    $legacy->setRelation('product', new Product(['product_type' => ProductType::Machine]));
    expect($legacy->trackingMode())->toBe(TrackingMode::Serial);

    $expiringProduct = Product::factory()->make([
        'product_type' => ProductType::ExpiryMaterial,
        'operational_profile' => null,
    ]);
    $newVariant = new ProductVariant;
    $newVariant->setRelation('product', $expiringProduct);
    $newVariant->product_id = 999999;
    $newVariant->tracking_mode = TrackingMode::None;
    $newVariant->tracks_expiration = true;
    (new ProductVariantObserver)->saving($newVariant);

    expect($newVariant->tracking_mode)->toBe(TrackingMode::Lot);
});

it('covers supplier product reference validation and availability projection', function (): void {
    $variant = ProductVariant::factory()->create();
    $unit = Unit::factory()->whole()->create();
    $supplier = Supplier::factory()->create();

    $base = [
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'purchase_cost' => '10.00',
        'availability_status' => 'active',
        'is_active' => true,
    ];

    expect(fn () => SupplierProductReference::query()->create([
        ...$base,
        'purchase_unit_id' => $unit->getKey(),
    ]))->toThrow(ValidationException::class, 'active purchase UoM')
        ->and(fn () => SupplierProductReference::query()->create([
            ...$base,
            'pack_size' => '0',
        ]))->toThrow(ValidationException::class, 'Pack size')
        ->and(fn () => SupplierProductReference::query()->create([
            ...$base,
            'valid_from' => today()->addDay(),
            'valid_to' => today(),
        ]))->toThrow(ValidationException::class, 'Valid to');

    $reference = SupplierProductReference::query()->create($base);
    $reference->availability_status = 'temporarily_unavailable';
    $reference->save();
    expect($reference->is_active)->toBeFalse();

    $reference->is_active = true;
    $reference->save();
    expect($reference->availability_status)->toBe('active');
});
