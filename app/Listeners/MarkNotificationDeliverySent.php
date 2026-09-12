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

        $delivery = NotificationDelivery::query()->find($event->notification->deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Sent,
            'sent_at' => now(),
            'failed_at' => null,
            'error' => null,
        ])->save();

        if ($delivery->template_key !== 'system.digest') {
            return;
        }

        $sourceIds = $delivery->variables['source_delivery_ids'] ?? [];
        if (! is_array($sourceIds) || $sourceIds === []) {
            return;
        }

        NotificationDelivery::query()
            ->whereKey($sourceIds)
            ->where('status', NotificationDeliveryStatus::Deferred->value)
            ->update([
                'status' => NotificationDeliveryStatus::Sent->value,
                'decision' => 'digested',
                'sent_at' => now(),
                'deferred_until' => null,
                'failed_at' => null,
                'error' => null,
            ]);
    }
}
