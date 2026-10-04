<?php

declare(strict_types=1);

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\ServiceAppointmentStatus;
use App\Enums\WarrantyEntitlementState;
use App\Models\EmployeeProfile;
use App\Models\EquipmentInstallation;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\Demo\DemoBaselineSeeder;
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

function seedInstallationFoundation(object $test): void
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
}

it('seeds one complete dental furnace installation through the domain services and is idempotent', function (): void {
    seedInstallationFoundation($this);

    $this->seed(DemoInstallationSeeder::class);

    $snapshot = fn (): array => [
        EquipmentInstallation::query()->count(),
        MaintenanceRecord::query()->where('maintenance_kind', MaintenanceKind::Installation->value)->count(),
        ServiceAppointment::query()->count(),
        SerializedInventoryUnit::query()->count(),
        WarrantyEntitlement::query()->count(),
        DB::table('inventory_operations')->count(),
        DB::table('notification_deliveries')->count(),
    ];
    $first = $snapshot();

    $this->seed(DemoInstallationSeeder::class);

    expect($snapshot())->toBe($first)
        ->and($first[0])->toBe(1)
        ->and($first[1])->toBe(1);
});

it('produces a delivered, installed, commissioned and accepted furnace with a commissioning-trigger warranty', function (): void {
    seedInstallationFoundation($this);
    $this->seed(DemoInstallationSeeder::class);

    $variant = ProductVariant::query()->where('sku', DemoInstallationSeeder::Sku)->firstOrFail();
    $unit = SerializedInventoryUnit::query()->where('product_variant_id', $variant->id)->sole();
    $installation = EquipmentInstallation::query()->with(['checks', 'shipment', 'maintenanceRecord'])->sole();
    $record = $installation->maintenanceRecord;
    $entitlement = WarrantyEntitlement::query()->where('serialized_inventory_unit_id', $unit->id)->sole();

    expect($installation->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($unit->custody_type->value)->toBe('customer')
        ->and((int) $unit->custody_reference_id)->toBe($record->customer_id)
        ->and($installation->shipment?->isArrived())->toBeTrue()
        ->and($installation->shipment?->tracking_number)->toBe('TRK-DEMO-INST-0001')
        ->and($installation->checks)->toHaveCount(6)
        ->and($installation->checks->every(fn ($check): bool => $check->result->satisfiesCommissioning()))->toBeTrue()
        ->and($installation->commissioning_status)->toBe(CommissioningStatus::Passed)
        ->and($installation->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Accepted)
        ->and($installation->customer_signatory_name)->toBe('Eng. Khaled Mansour')
        ->and($record->maintenance_kind)->toBe(MaintenanceKind::Installation)
        ->and($record->status)->toBe(MaintenanceStatus::Closed)
        ->and($record->description)->toStartWith(DemoInstallationSeeder::Marker)
        ->and($entitlement->state)->toBe(WarrantyEntitlementState::Active)
        ->and($entitlement->start_trigger->value)->toBe('commissioning')
        ->and($entitlement->starts_on?->toDateString())->toBe($installation->commissioned_at?->toDateString())
        ->and($entitlement->expires_on?->toDateString())->toBe($entitlement->starts_on?->addMonthsNoOverflow(24)->toDateString());
});

it('links the field visit to the same installation work and completes it', function (): void {
    seedInstallationFoundation($this);
    $this->seed(DemoInstallationSeeder::class);

    $installation = EquipmentInstallation::query()->sole();
    $appointment = ServiceAppointment::query()->with('serviceRecord')->sole();

    expect($appointment->serviceRecord?->maintenance_record_id)->toBe($installation->maintenance_record_id)
        ->and($appointment->isInstallation())->toBeTrue()
        ->and($appointment->status)->toBe(ServiceAppointmentStatus::Completed)
        ->and($appointment->customer_signature_name)->toBe('Eng. Khaled Mansour');
});
