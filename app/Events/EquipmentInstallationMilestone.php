<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\NotificationEventKey;
use App\Listeners\SendBusinessNotification;
use App\Models\MaintenanceRecord;
use App\Models\ServiceAppointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One business milestone of an equipment installation (scheduled, completed,
 * commissioning passed/failed, customer acceptance recorded). The notification
 * template is selected by `$key`; recipients are resolved by
 * {@see SendBusinessNotification}.
 */
final class EquipmentInstallationMilestone
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public MaintenanceRecord $record,
        public NotificationEventKey $key,
        public ?ServiceAppointment $appointment = null,
    ) {}
}
