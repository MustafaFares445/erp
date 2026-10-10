<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use App\Services\Employees\EmployeeOperationalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Release QA 2026-10-10: the Arabic employee view showed plan months in English ("October 2026").
it('names the plan month in the viewer language', function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 09:30'));
    $employee = EmployeeProfile::factory()->create();
    SalesPlan::factory()->create(['employee_id' => $employee->getKey(), 'month' => '2026-10-01']);
    app()->setLocale('ar');

    $context = app(EmployeeOperationalContextService::class);

    expect($context->overview($employee)['current_month'])->toBe('أكتوبر 2026')
        ->and($context->plans($employee)[0]['month'])->toBe('أكتوبر 2026');
});
