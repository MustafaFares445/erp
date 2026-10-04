<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\NotificationEventKey;
use App\Listeners\SendBusinessNotification;
use App\Models\MaintenanceRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A loaner or supplier-repair (RMA) milestone of a maintenance request. The
 * template is selected by `$key`; `$subjectId` is the loan or external repair
 * the milestone is about. Recipients are resolved by {@see SendBusinessNotification}.
 */
final class SupportContinuityMilestone
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public MaintenanceRecord $record,
        public NotificationEventKey $key,
        public int $subjectId,
    ) {}
}
