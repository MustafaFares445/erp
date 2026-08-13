<?php

declare(strict_types=1);

use App\Jobs\NotifyAdminOfSalaryRecalculation;
use App\Models\AuditLog;
use App\Models\EmployeeSalaryCalculation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs that the admin has been notified of a new salary calculation', function (): void {
    $calculation = EmployeeSalaryCalculation::factory()->create();

    new NotifyAdminOfSalaryRecalculation($calculation->id)->handle();

    $entry = AuditLog::query()
        ->where('description', 'salary.recalculation_notified')
        ->where('subject_id', $calculation->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->attribute_changes['attributes']['notified_at'] ?? null)->not->toBeNull();
});

it('does nothing when the salary calculation no longer exists', function (): void {
    new NotifyAdminOfSalaryRecalculation(999999)->handle();

    expect(AuditLog::query()->where('description', 'salary.recalculation_notified')->exists())->toBeFalse();
});
