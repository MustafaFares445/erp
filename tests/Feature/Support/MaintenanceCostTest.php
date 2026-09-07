<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Data\Support\ThirdPartyCostData;
use App\Enums\CostSource;
use App\Enums\MaintenanceStatus;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
});

function costTestManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** @return array{0: InventoryStock, 1: MaintenanceTask, 2: InventoryLot} */
function costTestStockedTask(float $onHand = 10.0): array
{
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => $onHand,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => $onHand,
    ]);
    $lot = InventoryLot::factory()
        ->for($stock->productVariant)
        ->for($stock->warehouse)
        ->create([
            'on_hand_quantity' => (string) $onHand,
            'reserved_quantity' => '0.000000',
            'expires_at' => null,
        ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);

    return [$stock, $task, $lot];
}

it('snapshots the last-received cost onto the consumption, and a later cost change does not restate it', function (): void {
    $manager = costTestManager();
    [$stock, $task, $lot] = costTestStockedTask(10.0);
    $supplier = Supplier::factory()->create();
    $reference = SupplierProductReference::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $stock->product_variant_id,
        'supplier_item_number' => 'SKU-1',
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $manager, $lot->getKey());

    expect($part->unit_cost_minor)->toBe(1000)
        ->and($part->total_cost_minor)->toBe(2000)
        ->and($part->cost_source)->toBe(CostSource::LastReceivedCost);

    // A later cost writeback must never restate an already-recorded consumption.
    $reference->update(['purchase_cost' => '25.00']);

    expect($part->refresh()->unit_cost_minor)->toBe(1000)
        ->and($part->total_cost_minor)->toBe(2000);
});

it('records no cost when no supplier reference exists, but still records the consumption', function (): void {
    $manager = costTestManager();
    [$stock, $task, $lot] = costTestStockedTask(10.0);

    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $manager, $lot->getKey());

    expect($part->unit_cost_minor)->toBeNull()
        ->and($part->total_cost_minor)->toBeNull()
        ->and($part->cost_source)->toBe(CostSource::Unknown);
});

it('reverses the cost snapshot alongside a reversed consumption', function (): void {
    $admin = User::factory()->admin()->create();
    [$stock, $task, $lot] = costTestStockedTask(10.0);
    $supplier = Supplier::factory()->create();
    SupplierProductReference::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $stock->product_variant_id,
        'supplier_item_number' => 'SKU-1',
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $service = app(ServiceRecordPartService::class);
    $part = $service->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $admin, $lot->getKey());

    expect($part->total_cost_minor)->toBe(2000);

    $service->reverse($part, $admin);

    expect($part->refresh()->unit_cost_minor)->toBeNull()
        ->and($part->total_cost_minor)->toBeNull()
        ->and($part->cost_source)->toBeNull();
});

it('aggregates recorded labour and third-party costs against the job', function (): void {
    $manager = costTestManager();
    $record = MaintenanceRecord::factory()->create();
    $employee = User::factory()->admin()->create();
    EmployeeProfile::factory()->withHourlyRate(6000)->create(['user_id' => $employee->getKey()]);

    $costService = app(MaintenanceCostService::class);

    $entry = $costService->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: null,
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 30,
    ), $manager);

    expect($entry->hourly_rate_minor)->toBe(6000)
        ->and($entry->total_cost_minor)->toBe(3000);

    $costService->recordThirdPartyCost(new ThirdPartyCostData(
        maintenanceRecordId: $record->getKey(),
        description: 'Subcontracted repair',
        amountMinor: 5000,
        incurredOn: now()->toDateString(),
    ), $manager);

    $jobCost = $costService->jobCost($record->refresh());

    expect($jobCost['labour_cost_minor'])->toBe(3000)
        ->and($jobCost['third_party_cost_minor'])->toBe(5000)
        ->and($jobCost['total_cost_minor'])->toBe(8000);
});

it('reports a warranty-covered job at its real cost against zero revenue', function (): void {
    $manager = costTestManager();
    [$stock, $task, $lot] = costTestStockedTask(10.0);
    $supplier = Supplier::factory()->create();
    SupplierProductReference::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $stock->product_variant_id,
        'supplier_item_number' => 'SKU-1',
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $manager, $lot->getKey());

    $record = $task->maintenanceRecord;
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::InProgress, $manager);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, $manager);

    $billed = app(MaintenanceBillingService::class)->markWarrantyCovered($record->refresh(), $manager, 'Unit under manufacturer warranty');

    $margin = app(MaintenanceCostService::class)->marginFor($billed);

    expect($margin['revenue_minor'])->toBe(0)
        ->and($margin['cost_minor'])->toBe(2000)
        ->and($margin['margin_minor'])->toBe(-2000)
        ->and($margin['billing_type'])->toBe('warranty_covered');
});

it('writes no journal entry when a part is consumed, even with a cost snapshot attached', function (): void {
    $admin = User::factory()->admin()->create();
    [$stock, $task, $lot] = costTestStockedTask(10.0);
    $supplier = Supplier::factory()->create();
    SupplierProductReference::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $stock->product_variant_id,
        'supplier_item_number' => 'SKU-1',
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $admin, $lot->getKey());

    expect($part->total_cost_minor)->toBe(2000)
        ->and(JournalEntry::query()->count())->toBe(0);

    app(ServiceRecordPartService::class)->reverse($part, $admin);

    expect(JournalEntry::query()->count())->toBe(0);
});
