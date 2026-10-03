<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum TicketServicePath: string implements HasLabel
{
    use HasTranslatedLabel;

    case RemoteSupport = 'remote_support';
    case Maintenance = 'maintenance';
    case OnSiteVisit = 'on_site_visit';
}
