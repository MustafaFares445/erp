<?php

declare(strict_types=1);

use App\Enums\EmployeeReportType;
use App\Enums\VisitStatus;
use App\Jobs\GenerateEmployeeReportExport;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeReportExport;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\EmployeeReportExportService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('requests an export, recording it as queued and dispatching the generation job', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = app(EmployeeReportExportService::class)->request(EmployeeReportType::PlanCompletion, [], $admin);

    expect($export->status)->toBe('queued');
    Bus::assertDispatched(GenerateEmployeeReportExport::class);
});

it('completes a queued export and produces a downloadable file', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    SalesPlan::factory()->withTasks(2)->create();

    $export = app(EmployeeReportExportService::class)->request(EmployeeReportType::PlanCompletion, [], $admin);
    new GenerateEmployeeReportExport($export->id)->handle(app(EmployeeReportExportService::class));

    $export->refresh();

    expect($export->status)->toBe('completed')
        ->and($export->file_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();

    $response = app(EmployeeReportExportService::class)->download($export, $admin);

    expect($response->getFile()->getPathname())->toBe(Storage::disk('local')->path((string) $export->file_path));
});

it('marks a failed export with a failure reason and cleans up the partial file', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = EmployeeReportExport::factory()->create([
        'type' => EmployeeReportType::PlanCompletion->value,
        'created_by' => $admin->id,
    ]);

    // Occupy the export directory's path with a plain file, so mkdir() for
    // the real export directory fails inside the try block — this is the
    // same technique InventoryExportServiceTest uses to force a mid-write
    // failure without mocking the XLSX writer itself.
    Storage::disk('local')->put('employee-reports', 'blocks the required directory');

    expect(fn () => app(EmployeeReportExportService::class)->generate($export))
        ->toThrow(LogicException::class);
    $export->refresh();

    expect($export->status)->toBe('failed')
        ->and($export->failure_reason)->toBe('Unable to create the private export directory.')
        ->and(Storage::disk('local')->exists(sprintf('employee-reports/%d.xlsx', $export->id)))->toBeFalse();
});

it('refuses to generate an export whose creator no longer resolves to a user', function (): void {
    Storage::fake('local');
    $export = EmployeeReportExport::factory()->create(['created_by' => null]);

    expect(fn () => app(EmployeeReportExportService::class)->generate($export))
        ->toThrow(DomainException::class);
});

it('refuses to download an export that has not finished generating', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = EmployeeReportExport::factory()->create(['status' => 'queued']);

    expect(fn () => app(EmployeeReportExportService::class)->download($export, $admin))
        ->toThrow(DomainException::class);
});

it('refuses to download a completed export whose file path was never recorded', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = EmployeeReportExport::factory()->create(['status' => 'completed', 'file_path' => null]);

    expect(fn () => app(EmployeeReportExportService::class)->download($export, $admin))
        ->toThrow(DomainException::class);
});

it('refuses to generate an export with an unrecognized report type', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = EmployeeReportExport::query()->create([
        'type' => 'not-a-real-type',
        'filters' => [],
        'status' => 'queued',
        'created_by' => $admin->id,
    ]);

    expect(fn () => app(EmployeeReportExportService::class)->generate($export))
        ->toThrow(DomainException::class);
});

it('treats a missing filters payload as an empty filter set when generating', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = EmployeeReportExport::query()->create([
        'type' => EmployeeReportType::PlanCompletion->value,
        'filters' => null,
        'status' => 'queued',
        'created_by' => $admin->id,
    ]);

    app(EmployeeReportExportService::class)->generate($export);
    $export->refresh();

    expect($export->status)->toBe('completed');
});

it('applies string-keyed filters when generating a report', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    SalesPlan::factory()->withTasks(1)->create();

    $export = app(EmployeeReportExportService::class)->request(
        EmployeeReportType::PlanCompletion,
        ['employee_id' => $employee->id],
        $admin,
    );
    $export->refresh();

    expect($export->status)->toBe('completed')
        ->and($export->filters)->toBe(['employee_id' => $employee->id]);
});

it('generates every report type end to end, exercising each heading and row mapper', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    SalesPlan::factory()->withTasks(1)->create();
    PlanTask::factory()->overdue()->create();
    CustomerVisit::factory()->create(['status' => VisitStatus::Planned]);
    EmployeePerformanceScore::factory()->create();
    EmployeeSalaryCalculation::factory()->create();

    foreach (EmployeeReportType::cases() as $type) {
        $export = app(EmployeeReportExportService::class)->request($type, [], $admin);
        $export->refresh();

        expect($export->status)->toBe('completed')
            ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();
    }
});
