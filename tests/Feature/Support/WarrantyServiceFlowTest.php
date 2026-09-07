<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\Ticket;
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

/**
 * F-07 (Docs/CROSS_MODULE_BUSINESS_FLOWS.md) — a ticket becomes a warranty
 * job whose real cost must stay visible even though it earns zero revenue
 * (GAP-MW-09).
 */
it('carries a ticket through maintenance, parts, labour, and warranty coverage with cost visible at zero revenue', function (): void {
    (new SupportPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create();

    $record = app(MaintenanceRecordService::class)->createFromTicket($ticket, [
        'description' => $ticket->description,
        'warranty_status' => WarrantyStatus::Covered->value,
        'warranty_expiry_date' => now()->addYear()->toDateString(),
    ], $manager);

    $task = app(ServiceRecordService::class)->create($record, [
        'title' => 'Diagnose and repair',
    ], $manager);

    $variant = ProductVariant::factory()->create(['base_price' => '80.00']);
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
    EmployeeProfile::factory()->withHourlyRate(4500)->create(['user_id' => $employee->getKey()]);

    app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: $task->getKey(),
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 90,
    ), $manager);

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $manager);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);
    app(MaintenanceRecordService::class)->transition($record->refresh(), MaintenanceStatus::Closed, $manager);

    app(MaintenanceBillingService::class)->markWarrantyCovered($record->refresh(), $manager, 'Unit is within its manufacturer warranty period');

    $margin = app(MaintenanceCostService::class)->marginFor($record->refresh());

    // Labour: 90 minutes at 45.00/hour = 67.50; part cost is unknown (no
    // supplier reference exists), so only labour contributes a known cost —
    // and it must still show up, not be hidden by the missing part cost.
    expect($margin['revenue_minor'])->toBe(0)
        ->and($margin['cost_minor'])->toBe(6750)
        ->and($margin['margin_minor'])->toBe(-6750)
        ->and($margin['billing_type'])->toBe('warranty_covered')
        ->and(JournalEntry::query()->count())->toBe(0);
});
