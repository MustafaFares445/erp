<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\EmployeeSalaryCalculation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SalaryConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public EmployeeSalaryCalculation $calculation) {}
}
