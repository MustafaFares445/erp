<?php

declare(strict_types=1);

namespace App\Events;

use App\Listeners\SendBusinessNotification;
use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once a service job's invoice is raised (WP-2.9, GAP-MW-10) —
 * mirrors {@see InvoiceIssued}'s shape so a listener wanting to notify the
 * customer of a billed job can be wired the same way
 * {@see SendBusinessNotification} wires the rest.
 */
final class MaintenanceRecordBilled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public MaintenanceRecord $record,
    ) {}
}
