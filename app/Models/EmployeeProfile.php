<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalaryCalculationMode;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\EmployeeProfileFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'employee_code',
    'job_title',
    'phone',
    'email',
    'is_active',
    'use_base_salary',
    'base_salary',
    'commission_target_amount',
    'salary_calculation_mode',
])]
final class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    protected static function booted(): void
    {
        self::saving(function (EmployeeProfile $profile): void {
            if ($profile->use_base_salary && $profile->base_salary === null) {
                throw new DomainException(__('admin.employees.errors.missing_base_salary'));
            }

            if (! $profile->use_base_salary && $profile->commission_target_amount === null) {
                throw new DomainException(__('admin.employees.errors.missing_commission_target'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'use_base_salary' => 'boolean',
            'base_salary' => 'decimal:2',
            'commission_target_amount' => 'decimal:2',
            'salary_calculation_mode' => SalaryCalculationMode::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
