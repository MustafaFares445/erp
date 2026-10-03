<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeOnboardingService;
use Illuminate\Support\Facades\Hash;

/**
 * The six demo employees. They are created first because quotations, visits, plans and
 * maintenance all reference an employee profile.
 */
final class DemoEmployeeRosterSeeder extends DemoSeeder
{
    /** @var array<string, array{name: string, title: string, base: ?float, target: ?float, rate: int}> */
    public const array Employees = [
        'DEMO-EMP-001' => ['name' => 'Omar Saleh', 'title' => 'Senior Sales Representative', 'base' => 7500.0, 'target' => null, 'rate' => 6500],
        'DEMO-EMP-002' => ['name' => 'Lina Haddad', 'title' => 'Sales Representative', 'base' => 6000.0, 'target' => null, 'rate' => 5000],
        'DEMO-EMP-003' => ['name' => 'Kareem Mansour', 'title' => 'Field Sales Representative', 'base' => null, 'target' => 5500.0, 'rate' => 5000],
        'DEMO-EMP-004' => ['name' => 'Nour Khalil', 'title' => 'Technical Sales Representative', 'base' => 6500.0, 'target' => null, 'rate' => 5500],
        'DEMO-EMP-005' => ['name' => 'Samer Darwish', 'title' => 'Service Technician', 'base' => 5500.0, 'target' => null, 'rate' => 7000],
        'DEMO-EMP-006' => ['name' => 'Hala Ibrahim', 'title' => 'Sales Coordinator', 'base' => null, 'target' => 4800.0, 'rate' => 4500],
    ];

    protected function seed(DemoContext $context): void
    {
        $context->at('2026-09-04 08:15');
        $context->as('employee_manager');

        foreach (self::Employees as $code => $employee) {
            if (EmployeeProfile::query()->where('employee_code', $code)->exists()) {
                continue;
            }

            $number = mb_substr($code, -3);

            $profile = app(EmployeeOnboardingService::class)->onboard([
                'name' => $employee['name'],
                'login_email' => "demo.emp{$number}@ierp.test",
                'username' => "demo-emp-{$number}",
                'job_title' => $employee['title'],
                'phone' => "+97155500{$number}",
                'use_base_salary' => $employee['base'] !== null,
                'base_salary' => $employee['base'],
                'commission_target_amount' => $employee['target'],
            ]);

            $profile->forceFill([
                'employee_code' => $code,
                'default_hourly_rate_minor' => $employee['rate'],
            ])->saveQuietly();

            $profile->user->forceFill(['password' => Hash::make(DemoContext::Password)])->save();
        }
    }
}
