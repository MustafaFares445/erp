<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalaryCalculationMode;
use App\Enums\UserType;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class EmployeeOnboardingService
{
    private const int MaxEmployeeCodeAttempts = 20;

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function onboard(array $data): EmployeeProfile
    {
        return DB::transaction(function () use ($data): EmployeeProfile {
            $useBaseSalary = (bool) ($data['use_base_salary'] ?? true);

            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'] ?? null,
                'email' => $data['login_email'],
                'password' => Hash::make(Str::random(32)),
                'user_type' => UserType::Employee,
            ]);

            $profile = EmployeeProfile::query()->create([
                'user_id' => $user->id,
                'employee_code' => $this->generateEmployeeCode(),
                'job_title' => $data['job_title'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['login_email'],
                'is_active' => true,
                'use_base_salary' => $useBaseSalary,
                'base_salary' => $data['base_salary'] ?? null,
                'commission_target_amount' => $data['commission_target_amount'] ?? null,
                'salary_calculation_mode' => $useBaseSalary
                    ? SalaryCalculationMode::BasePlusPerformance
                    : SalaryCalculationMode::PerformanceOnly,
            ]);

            $this->auditLogger->log(
                action: 'employee.created',
                entity: $profile,
                newValues: $profile->getAttributes(),
            );

            return $profile;
        });
    }

    private function generateEmployeeCode(): string
    {
        for ($attempt = 0; $attempt < self::MaxEmployeeCodeAttempts; $attempt++) {
            $code = 'EMP-'.mb_str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            if (! EmployeeProfile::withTrashed()->where('employee_code', $code)->exists()) {
                return $code;
            }
        }

        // @codeCoverageIgnoreStart
        // Requires 20 consecutive collisions against a 4-digit cryptographically
        // random code — reproducing it deterministically would mean pre-creating
        // all 10,000 possible codes (and 10,000 unique users, since user_id is
        // also unique), which is disproportionate setup for one guard clause.
        throw new RuntimeException('Unable to generate a unique employee code.');
        // @codeCoverageIgnoreEnd
    }
}
