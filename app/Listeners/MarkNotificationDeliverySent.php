<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Notifications\BusinessNotification;
use Illuminate\Notifications\Events\NotificationSent;

final readonly class MarkNotificationDeliverySent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof BusinessNotification) {
            return;
        }

        NotificationDelivery::query()
            ->whereKey($event->notification->deliveryId)
            ->update([
                'status' => NotificationDeliveryStatus::Sent->value,
                'sent_at' => now(),
                'failed_at' => null,
                'error' => null,
            ]);
    }
}
