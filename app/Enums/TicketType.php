<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * Fixed catalogue of ticket types (FR-011), seeded as the default set and
 * treated as fixed for this phase (spec.md §Assumptions).
 */
enum TicketType: string implements HasLabel
{
    use HasTranslatedLabel;

    case SoftwareIssue = 'software_issue';
    case HardwareIssue = 'hardware_issue';
    case GeneralSupport = 'general_support';
    case MaintenanceRequest = 'maintenance_request';
}
