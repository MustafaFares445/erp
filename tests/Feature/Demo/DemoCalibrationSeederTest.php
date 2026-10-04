<?php

declare(strict_types=1);

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\ServiceAppointmentStatus;
use App\Models\EmployeeProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\Demo\DemoBaselineSeeder;
use Database\Seeders\Demo\DemoCalibrationSeeder;
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

function seedCalibrationFoundation(object $test, bool $withHandpiece = true): void
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

    if ($withHandpiece) {
        SerializedInventoryUnit::factory()->create([
            'serial_number' => 'SN019-HANDPIECE-TEST',
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => SerializedInventoryUnit::query()->where('serial_number', 'like', 'SN021-FURNACE%')->value('custody_reference_id'),
        ]);
    }
}

it('seeds a passed furnace calibration with certificate, next due date and a six-monthly calibration schedule', function (): void {
    seedCalibrationFoundation($this);
    $this->seed(DemoCalibrationSeeder::class);

    $furnace = SerializedInventoryUnit::query()->where('serial_number', 'like', 'SN021-FURNACE%')->firstOrFail();
    $calibration = EquipmentCalibration::query()->with(['measurements', 'maintenanceRecord', 'performedBy'])->where('serialized_inventory_unit_id', $furnace->id)->sole();
    $schedule = MaintenanceSchedule::query()->where('serialized_inventory_unit_id', $furnace->id)->sole();

    expect($calibration->result)->toBe(CalibrationResult::Passed)
        ->and($calibration->certificate_number)->toBe(DemoCalibrationSeeder::PassedCertificate)
        ->and($calibration->certificate_expires_on?->toDateString())->toBe('2027-10-02')
        ->and($calibration->next_calibration_due_on?->toDateString())->toBe('2027-04-02')
        ->and($calibration->measurements)->toHaveCount(2)
        ->and($calibration->measurements->every(fn ($m): bool => $m->result->value === 'passed'))->toBeTrue()
        ->and($calibration->measurements->first()->actual_value)->toBe('943.0000')
        ->and($calibration->performedBy)->not->toBeNull()
        ->and($calibration->maintenanceRecord->maintenance_kind)->toBe(MaintenanceKind::Calibration)
        ->and($calibration->maintenanceRecord->status)->toBe(MaintenanceStatus::Closed)
        ->and($calibration->maintenanceRecord->description)->toStartWith(DemoCalibrationSeeder::Marker)
        ->and($schedule->maintenance_kind)->toBe(MaintenanceKind::Calibration)
        ->and($schedule->interval_type)->toBe(MaintenanceIntervalType::Months)
        ->and($schedule->interval_value)->toBe(6)
        ->and($schedule->first_due_on->toDateString())->toBe('2027-04-02');
});

it('seeds a failed handpiece calibration with its failed measurement and a follow-up repair request', function (): void {
    seedCalibrationFoundation($this);
    $this->seed(DemoCalibrationSeeder::class);

    $handpiece = SerializedInventoryUnit::query()->where('serial_number', 'SN019-HANDPIECE-TEST')->firstOrFail();
    $calibration = EquipmentCalibration::query()->with(['measurements', 'followUpMaintenanceRecord'])->where('serialized_inventory_unit_id', $handpiece->id)->sole();

    expect($calibration->result)->toBe(CalibrationResult::Failed)
        ->and($calibration->failure_reason)->toContain('bearing replacement')
        ->and($calibration->certificate_number)->toBeNull()
        ->and($calibration->next_calibration_due_on)->toBeNull()
        ->and($calibration->measurements->sole()->result->value)->toBe('failed')
        ->and($calibration->followUpMaintenanceRecord?->maintenance_kind)->toBe(MaintenanceKind::Corrective)
        ->and($calibration->followUpMaintenanceRecord?->status)->toBe(MaintenanceStatus::Open)
        ->and($calibration->followUpMaintenanceRecord?->serialized_inventory_unit_id)->toBe($handpiece->id);
});

it('links each calibration visit to the same work and completes it', function (): void {
    seedCalibrationFoundation($this);
    $this->seed(DemoCalibrationSeeder::class);

    $appointments = ServiceAppointment::query()
        ->with('serviceRecord.maintenanceRecord')
        ->whereHas('serviceRecord.maintenanceRecord', fn ($query) => $query->where('maintenance_kind', MaintenanceKind::Calibration->value))
        ->get();

    expect($appointments)->toHaveCount(2)
        ->and($appointments->every(fn (ServiceAppointment $appointment): bool => $appointment->isCalibration() && $appointment->status === ServiceAppointmentStatus::Completed))->toBeTrue()
        ->and($appointments->every(fn (ServiceAppointment $appointment): bool => EquipmentCalibration::query()->where('maintenance_record_id', $appointment->serviceRecord?->maintenance_record_id)->exists()))->toBeTrue();
});

it('is idempotent', function (): void {
    seedCalibrationFoundation($this);
    $this->seed(DemoCalibrationSeeder::class);

    $snapshot = fn (): array => [
        EquipmentCalibration::query()->count(),
        DB::table('equipment_calibration_measurements')->count(),
        MaintenanceRecord::query()->count(),
        MaintenanceSchedule::query()->count(),
        DB::table('maintenance_schedule_occurrences')->count(),
        ServiceAppointment::query()->count(),
        DB::table('notification_deliveries')->count(),
    ];
    $first = $snapshot();

    $this->seed(DemoCalibrationSeeder::class);

    expect($snapshot())->toBe($first)
        ->and($first[0])->toBe(2);
});

it('resumes an interrupted run instead of duplicating or closing unfinished work', function (): void {
    seedCalibrationFoundation($this);
    $this->seed(DemoCalibrationSeeder::class);

    // Rewind the failed scenario to "started, not yet failed".
    $failed = EquipmentCalibration::query()->where('result', 'failed')->sole();
    $failed->update(['result' => null, 'failure_reason' => null, 'calibrated_at' => null, 'follow_up_maintenance_record_id' => null]);
    $failed->maintenanceRecord->update(['status' => MaintenanceStatus::InProgress]);

    $this->seed(DemoCalibrationSeeder::class);

    expect(EquipmentCalibration::query()->count())->toBe(2)
        ->and($failed->fresh()->result)->toBe(CalibrationResult::Failed)
        ->and(MaintenanceRecord::query()->where('maintenance_kind', MaintenanceKind::Calibration->value)->count())->toBe(2);
});

it('skips scenarios whose equipment is not in the demo data', function (): void {
    seedCalibrationFoundation($this, withHandpiece: false);
    $this->seed(DemoCalibrationSeeder::class);

    expect(EquipmentCalibration::query()->count())->toBe(1);

    SerializedInventoryUnit::query()->where('serial_number', 'like', 'SN021-FURNACE%')->update(['custody_type' => SerializedCustodyType::Warehouse]);
    DB::table('equipment_calibrations')->delete();
    $this->seed(DemoCalibrationSeeder::class);

    expect(EquipmentCalibration::query()->count())->toBe(0);
});
