<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Data\Support\ThirdPartyCostData;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers maintenance labour validation branches', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $actor = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    $service = app(MaintenanceCostService::class);

    expect(fn (): mixed => $service->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: null,
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 0,
    ), $actor))->toThrow(ValidationException::class, 'Labour minutes must be greater than zero.');

    expect(fn (): mixed => $service->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: null,
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 30,
    ), $actor))->toThrow(ValidationException::class, 'An hourly rate is required');
});
it('covers maintenance third-party cost positive amount validation', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $actor = User::factory()->admin()->create();

    expect(fn (): mixed => app(MaintenanceCostService::class)->recordThirdPartyCost(
        new ThirdPartyCostData(
            maintenanceRecordId: $record->getKey(),
            description: 'Coverage invalid cost',
            amountMinor: 0,
            incurredOn: now()->toDateString(),
        ),
        $actor,
    ))->toThrow(ValidationException::class, 'The cost amount must be greater than zero.');
});
