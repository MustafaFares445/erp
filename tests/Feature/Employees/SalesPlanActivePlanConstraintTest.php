<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a second active plan for the same employee/month at the database level, bypassing the service', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $month = now()->startOfMonth()->toDateString();

    SalesPlan::factory()->create([
        'employee_id' => $employee->id,
        'month' => $month,
        'active_month' => $month,
    ]);

    expect(fn () => SalesPlan::factory()->create([
        'employee_id' => $employee->id,
        'month' => $month,
        'active_month' => $month,
    ]))->toThrow(QueryException::class);
});

it('allows multiple non-active plans for the same employee/month, since NULL active_month values are distinct', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $month = now()->startOfMonth()->toDateString();

    SalesPlan::factory()->create(['employee_id' => $employee->id, 'month' => $month, 'active_month' => null]);
    SalesPlan::factory()->create(['employee_id' => $employee->id, 'month' => $month, 'active_month' => null]);

    expect(SalesPlan::query()->where('employee_id', $employee->id)->count())->toBe(2);
});
