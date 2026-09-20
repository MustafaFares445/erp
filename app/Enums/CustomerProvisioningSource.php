<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Crm\CustomerAccountProvisioningService;

/**
 * Distinguishes the two current callers of
 * {@see CustomerAccountProvisioningService}, recorded as the
 * activity log `source_channel` property.
 */
enum CustomerProvisioningSource: string
{
    case JoinUs = 'join_us';
    case Dashboard = 'dashboard';
}
