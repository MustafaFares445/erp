<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * Distinguishes customer-account provisioning channels, recorded as the
 * activity log `source_channel` property.
 */
enum CustomerProvisioningSource: string implements HasLabel
{
    use HasTranslatedLabel;

    case JoinUs = 'join_us';
    case Dashboard = 'dashboard';
}
