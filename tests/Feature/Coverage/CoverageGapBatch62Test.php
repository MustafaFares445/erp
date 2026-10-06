<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\OccurrenceStatus;
use App\Enums\ServiceAppointmentStatus;
use App\Enums\TicketStatus;
use App\Enums\WarrantyFailureCategory;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\SupportReportService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage62Manager(): User
{
    (new SupportPermissionSeeder)->run();
    (new CurrencySeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function coverage62Fixtures(): array
{
    $employee = EmployeeProfile::factory()->create();

    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'assigned_employee_id' => $employee->id,
        'created_at' => now()->subHour(),
    ]);

    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    ServiceAppointment::query()->create([
        'maintenance_task_id' => $task->id,
        'employee_id' => $employee->id,
        'status' => ServiceAppointmentStatus::Completed,
        'scheduled_start_at' => now()->subHours(3),
        'scheduled_end_at' => now()->subHours(2),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Coverage site'],
        'checked_in_at' => now()->subMinutes(75),
        'checked_out_at' => now()->subMinutes(15),
        'customer_signature_name' => 'Coverage customer',
    ]);

    $unit = SerializedInventoryUnit::factory()->create();
    foreach ([1, 2] as $hours) {
        MaintenanceRecord::factory()->create([
            'serialized_inventory_unit_id' => $unit->id,
            'failure_category' => WarrantyFailureCategory::NormalComponentFailure,
            'created_at' => now()->subHours($hours),
        ]);
    }

    $marginRecord = MaintenanceRecord::factory()->create([
        'billing_type' => MaintenanceBillingType::WarrantyCovered,
        'billed_at' => now(),
    ]);

    $occurrence = MaintenanceScheduleOccurrence::factory()->create([
        'status' => OccurrenceStatus::Completed,
        'due_on' => today(),
    ]);

    return ['employee' => $employee, 'unit' => $unit, 'marginRecord' => $marginRecord, 'occurrence' => $occurrence];
}

it('covers reliability financial and preventive support-report view branches', function (): void {
    $manager = coverage62Manager();
    coverage62Fixtures();
    $this->actingAs($manager);

    foreach (['reliability_warranty', 'financial', 'preventive'] as $section) {
        $page = app(ViewSupportReports::class);
        $page->section = $section;
        $page->from = now()->subDay()->toDateString();
        $page->until = now()->addDay()->toDateString();

        $data = $page->getViewData();

        expect($data['sectionKey'])->toBe($section)
            ->and($data)->toHaveKey('currency');
    }
});

it('covers every support-report export routing branch with populated loop rows', function (): void {
    $manager = coverage62Manager();
    coverage62Fixtures();
    $this->actingAs($manager);

    $page = app(ViewSupportReports::class);
    $service = app(SupportReportService::class);
    $from = now()->subDay();
    $until = now()->addDay();
    $currency = app(CurrencyCatalogService::class)->defaultCode();
    $exportRows = new ReflectionMethod(ViewSupportReports::class, 'exportRows');

    $workload = $exportRows->invoke($page, 'workload', $service, $manager, $from, $until, $currency);
    expect(collect($workload)->contains(fn (array $row): bool => ($row[0] ?? null) === __('Assignee')))->toBeTrue();

    $field = $exportRows->invoke($page, 'field_service', $service, $manager, $from, $until, $currency);
    expect(collect($field)->contains(fn (array $row): bool => ($row[1] ?? null) === 1 && ($row[2] ?? null) === 60))->toBeTrue();

    $reliability = $exportRows->invoke($page, 'reliability_warranty', $service, $manager, $from, $until, $currency);
    expect(collect($reliability)->contains(fn (array $row): bool => ($row[2] ?? null) === 2))->toBeTrue();

    $financial = $exportRows->invoke($page, 'financial', $service, $manager, $from, $until, $currency);
    expect(collect($financial)->contains(fn (array $row): bool => is_int($row[0] ?? null)))->toBeTrue();

    $preventive = $exportRows->invoke($page, 'preventive', $service, $manager, $from, $until, $currency);
    expect(collect($preventive)->contains(fn (array $row): bool => count($row) === 4 && ($row[1] ?? null) === 1))->toBeTrue();
});

it('covers support-report csv scalar conversion fallbacks', function (): void {
    $csvValue = new ReflectionMethod(ViewSupportReports::class, 'csvValue');

    expect($csvValue->invoke(null, true))->toBe(1)
        ->and($csvValue->invoke(null, false))->toBe(0)
        ->and($csvValue->invoke(null, ['unsupported']))->toBe('');
});
