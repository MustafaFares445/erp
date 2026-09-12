<?php

declare(strict_types=1);

use App\Enums\EmployeeReportType;
use App\Enums\VisitStatus;
use App\Jobs\GenerateDocumentExport;
use App\Models\CustomerVisit;
use App\Models\DocumentExport;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\EmployeeReportExportService;
use App\Services\Exports\DocumentExportService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('requests employee reports through the canonical retained export job', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = app(EmployeeReportExportService::class)->request(EmployeeReportType::PlanCompletion, [], $admin);

    expect($export)->toBeInstanceOf(DocumentExport::class)
        ->and($export->module)->toBe('employees')
        ->and($export->status)->toBe('queued');
    Bus::assertDispatched(GenerateDocumentExport::class);
});

it('completes a queued employee export with row count and a downloadable file', function (): void {
    Storage::fake('local');
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    SalesPlan::factory()->withTasks(2)->create();

    $export = app(EmployeeReportExportService::class)->request(EmployeeReportType::PlanCompletion, [], $admin);
    new GenerateDocumentExport($export->id)->handle(app(DocumentExportService::class));
    $export->refresh();

    expect($export->status)->toBe('completed')
        ->and($export->row_count)->toBeGreaterThan(0)
        ->and($export->file_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();

    $response = app(EmployeeReportExportService::class)->download($export, $admin);
    expect($response->getFile()->getPathname())->toBe(Storage::disk('local')->path((string) $export->file_path));
});

it('marks canonical employee generation failures and removes partial files', function (): void {
    Storage::fake('local');
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $export = app(EmployeeReportExportService::class)->request(EmployeeReportType::PlanCompletion, [], $admin);
    Storage::disk('local')->put('employee-reports', 'blocks the required directory');

    expect(fn () => app(EmployeeReportExportService::class)->generate($export))
        ->toThrow(LogicException::class);

    $export->refresh();
    expect($export->status)->toBe('failed')
        ->and($export->failure_reason)->toBe('Unable to create the private export directory.');
});

it('refuses canonical employee exports without a resolvable requester or valid report type', function (): void {
    $service = app(EmployeeReportExportService::class);
    $orphan = DocumentExport::query()->create([
        'module' => 'employees',
        'type' => EmployeeReportType::PlanCompletion->value,
        'format' => 'xlsx',
        'parameters' => ['filters' => []],
        'status' => 'queued',
        'created_by' => null,
        'expires_at' => now()->addDay(),
    ]);

    expect(fn () => $service->generate($orphan))->toThrow(DomainException::class);

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $invalid = DocumentExport::query()->create([
        'module' => 'employees',
        'type' => 'not-a-real-type',
        'format' => 'xlsx',
        'parameters' => ['filters' => []],
        'status' => 'queued',
        'created_by' => $admin->id,
        'expires_at' => now()->addDay(),
    ]);

    expect(fn () => $service->generate($invalid))->toThrow(DomainException::class);
});

it('retains normalized employee report filters in the canonical parameters', function (): void {
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
    )->fresh();

    expect($export->status)->toBe('completed')
        ->and($export->filters)->toBe(['employee_id' => $employee->id]);
});

it('generates every employee report type end to end through DocumentExport', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    SalesPlan::factory()->withTasks(1)->create();
    PlanTask::factory()->overdue()->create();
    CustomerVisit::factory()->create(['status' => VisitStatus::Planned]);
    EmployeePerformanceScore::factory()->create();
    EmployeeSalaryCalculation::factory()->create();

    foreach (EmployeeReportType::cases() as $type) {
        $export = app(EmployeeReportExportService::class)->request($type, [], $admin)->fresh();

        expect($export->status)->toBe('completed')
            ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();
    }
});
