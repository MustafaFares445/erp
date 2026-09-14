<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketServicePath: string
{
    case RemoteSupport = 'remote_support';
    case Maintenance = 'maintenance';
}
