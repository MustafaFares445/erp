<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Crm\CustomerAccountProvisioningService;

/**
 * Distinguishes customer-account provisioning channels, recorded as the
 * activity log `source_channel` property.
 */
enum CustomerProvisioningSource: string
{
    case JoinUs = 'join_us';
    case Dashboard = 'dashboard';
}
