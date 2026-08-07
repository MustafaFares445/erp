<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CustomerVisit;

/**
 * How a {@see CustomerVisit} came to exist (data-model.md §5).
 * `Field` is written only by the out-of-scope employee app (D10); the
 * dashboard reads and honours the flag but never sets it.
 */
enum VisitRecordChannel: string
{
    case Dashboard = 'Dashboard';
    case Field = 'Field';
}
