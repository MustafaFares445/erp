<?php

declare(strict_types=1);

use App\Enums\EquipmentLoanStatus;
use App\Enums\ExternalRepairStatus;
use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Models\EmployeeProfile;
use App\Models\EquipmentLoan;
use App\Models\InventoryMovement;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\Demo\DemoBaselineSeeder;
use Database\Seeders\Demo\DemoCalibrationSeeder;
use Database\Seeders\Demo\DemoContinuitySeeder;
use Database\Seeders\Demo\DemoEmployeeRosterSeeder;
use Database\Seeders\Demo\DemoInstallationSeeder;
use Database\Seeders\Demo\DemoInventoryOpeningSeeder;
use Database\Seeders\Demo\DemoMasterDataSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PackageTypeSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function seedContinuityFoundation(object $test): void
{
    foreach ([
        CurrencySeeder::class, InventoryPermissionSeeder::class, EmployeePermissionSeeder::class,
        PurchasePermissionSeeder::class, SalesPermissionSeeder::class, SystemPermissionSeeder::class,
        AccountingPermissionSeeder::class, CrmPermissionSeeder::class, SupportPermissionSeeder::class,
        ChartOfAccountsSeeder::class, PackageTypeSeeder::class, NotificationTemplateSeeder::class,
        DemoBaselineSeeder::class, DemoMasterDataSeeder::class, DemoEmployeeRosterSeeder::class, DemoInventoryOpeningSeeder::class,
    ] as $seeder) {
        $test->seed($seeder);
    }

    $technician = User::factory()->employee()->create(['email' => 'demo.support.agent1.login@ierp.test']);
    $technician->assignRole('Support Agent');
    EmployeeProfile::factory()->create(['user_id' => $technician->id, 'email' => 'demo.support.agent1@ierp.test', 'is_active' => true]);

    $test->seed(DemoInstallationSeeder::class);

    // The opening stock holds the demo handpiece; hand it to the furnace customer so the failed-calibration scenario applies.
    SerializedInventoryUnit::query()->where('serial_number', 'SN019-HANDPIECE-0001')->firstOrFail()->forceFill([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => SerializedInventoryUnit::query()->where('serial_number', 'like', 'SN021-FURNACE%')->value('custody_reference_id'),
    ])->save();

    $test->seed(DemoCalibrationSeeder::class);
}

it('gives the customer an issued loaner for the failed calibration follow-up repair and approves its supplier repair', function (): void {
    seedContinuityFoundation($this);
    $this->seed(DemoContinuitySeeder::class);

    $loan = EquipmentLoan::query()->with(['loanerUnit', 'originalUnit', 'maintenanceRecord'])->sole();
    $rma = MaintenanceExternalRepair::query()->with('maintenanceRecord')->sole();

    expect($loan->status)->toBe(EquipmentLoanStatus::Issued)
        ->and($loan->maintenanceRecord->maintenance_kind)->toBe(MaintenanceKind::Corrective)
        ->and($loan->maintenanceRecord->description)->toContain('failed calibration')
        ->and($loan->loanerUnit->custody_type)->toBe(SerializedCustodyType::Customer)
        ->and((int) $loan->loanerUnit->custody_reference_id)->toBe($loan->customer_id)
        ->and($loan->loanerUnit->product_variant_id)->toBe($loan->originalUnit->product_variant_id)
        ->and($loan->expected_return_at?->toDateString())->toBe('2026-10-09')
        ->and(InventoryMovement::query()->where('source_type', 'equipment_loan')->where('source_id', $loan->id)->count())->toBe(1)
        ->and($rma->status)->toBe(ExternalRepairStatus::Approved)
        ->and($rma->rma_number)->toBe(DemoContinuitySeeder::RmaNumber)
        ->and($rma->maintenance_record_id)->toBe($loan->maintenance_record_id)
        ->and($rma->serialized_inventory_unit_id)->toBe($loan->original_serialized_inventory_unit_id)
        ->and(MaintenanceRecord::query()->find($loan->maintenance_record_id)?->status->value)->not->toBe('closed');
});

it('is idempotent', function (): void {
    seedContinuityFoundation($this);
    $this->seed(DemoContinuitySeeder::class);

    $snapshot = fn (): array => [
        EquipmentLoan::query()->count(),
        MaintenanceExternalRepair::query()->count(),
        InventoryMovement::query()->count(),
        MaintenanceRecord::query()->count(),
        User::query()->count(),
        DB::table('notification_deliveries')->count(),
    ];
    $first = $snapshot();

    $this->seed(DemoContinuitySeeder::class);

    expect($snapshot())->toBe($first)
        ->and($first[0])->toBe(1)
        ->and($first[1])->toBe(1);
});

it('skips quietly when the demo has no failed calibration follow-up', function (): void {
    seedContinuityFoundation($this);
    DB::table('equipment_calibrations')->update(['follow_up_maintenance_record_id' => null]);
    $this->seed(DemoContinuitySeeder::class);

    expect(EquipmentLoan::query()->count())->toBe(0)
        ->and(MaintenanceExternalRepair::query()->count())->toBe(0);
});
