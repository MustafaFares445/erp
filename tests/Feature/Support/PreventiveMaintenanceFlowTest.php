<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('raises a preventive job from a schedule and carries it through parts, labour, warranty coverage, and service history', function (): void {
    (new SupportPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    $schedule = app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
        serializedInventoryUnitId: $unit->getKey(),
        customerId: $customer->getKey(),
        name: 'Annual filter service',
        intervalType: MaintenanceIntervalType::Months,
        intervalValue: 12,
        leadTimeDays: 14,
        firstDueOn: now()->toDateString(),
        billingType: MaintenanceBillingType::WarrantyCovered,
    ), $manager);

    $raised = app(MaintenanceScheduleGenerator::class)->raiseDue();
    expect($raised)->toBe(1);

    $occurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    expect($occurrence->status)->toBe(OccurrenceStatus::Raised);

    /** @var MaintenanceRecord $record */
    $record = $occurrence->maintenanceRecord;
    expect($record)->not->toBeNull()
        ->and($record->serial_number)->toBe($unit->serial_number)
        ->and($record->billing_type)->toBe(MaintenanceBillingType::Unbilled);

    $task = app(ServiceRecordService::class)->create($record, ['title' => 'Replace filter'], $manager);

    $variant = ProductVariant::factory()->create(['base_price' => '60.00']);
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 5,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($stock->warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    app(ServiceRecordPartService::class)->consume($task, $variant->getKey(), $stock->warehouse_id, 1.0, $manager, $lot->getKey());

    $employee = User::factory()->admin()->create();
    EmployeeProfile::factory()->withHourlyRate(4000)->create(['user_id' => $employee->getKey()]);

    app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: $task->getKey(),
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 60,
    ), $manager);

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $manager);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);
    app(MaintenanceRecordService::class)->transition($record->refresh(), MaintenanceStatus::Closed, $manager);

    $occurrence->refresh();
    $schedule->refresh();

    expect($occurrence->status)->toBe(OccurrenceStatus::Completed)
        ->and($schedule->last_completed_on->toDateString())->toBe(now()->toDateString());

    app(MaintenanceBillingService::class)->markWarrantyCovered($record->refresh(), $manager, 'Annual service covered under equipment warranty');

    $margin = app(MaintenanceCostService::class)->marginFor($record->refresh());

    expect($margin['revenue_minor'])->toBe(0)
        ->and($margin['cost_minor'])->toBe(4000)
        ->and($margin['billing_type'])->toBe('warranty_covered');

    $serviceHistory = MaintenanceRecord::query()
        ->where('serialized_inventory_unit_id', $unit->getKey())
        ->get();

    expect($serviceHistory)->toHaveCount(1)
        ->and($serviceHistory->first()->getKey())->toBe($record->getKey())
        ->and($record->serviceRecords()->count())->toBe(1)
        ->and($record->serviceRecords->first()->parts()->count())->toBe(1);
});
