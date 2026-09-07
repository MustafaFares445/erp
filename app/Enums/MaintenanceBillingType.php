<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceBillingService;

/**
 * How a {@see MaintenanceRecord} job's cost was (or was not) recovered
 * (WP-2.9, GAP-MW-09/GAP-MW-10). `Unbilled` is the default for every job;
 * `WarrantyCovered` and `TicketSettled` recognise zero revenue against a real
 * cost (F-07); `Quoted` and `Invoiced` are the standard Sales billing path
 * (F-06), set only by {@see MaintenanceBillingService}.
 */
enum MaintenanceBillingType: string
{
    case Unbilled = 'unbilled';
    case WarrantyCovered = 'warranty_covered';
    case TicketSettled = 'ticket_settled';
    case Quoted = 'quoted';
    case Invoiced = 'invoiced';

    /**
     * Whether a job in this billing state has already recovered (or has been
     * declared never to recover) its cost — used to guard against billing a
     * job twice.
     */
    public function isSettled(): bool
    {
        return $this !== self::Unbilled;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
