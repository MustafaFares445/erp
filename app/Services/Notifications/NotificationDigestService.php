<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

final readonly class NotificationDigestService
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    /** @return array{released:int,digests:int,failed:int} */
    public function processDue(int $limit = 1000): array
    {
        $due = NotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::Deferred->value)
            ->whereNotNull('deferred_until')
            ->where('deferred_until', '<=', now())
            ->orderBy('deferred_until')
            ->limit($limit)
            ->get();

        $released = 0;
        $digests = 0;
        $failed = 0;

        foreach ($due->where('decision', 'quiet_hours') as $delivery) {
            try {
                $this->dispatcher->releaseDeferred($delivery);
                $released++;
            } catch (Throwable $throwable) {
                $delivery->forceFill([
                    'status' => NotificationDeliveryStatus::Failed,
                    'error' => mb_substr($throwable->getMessage(), 0, 500),
                    'failed_at' => now(),
                    'deferred_until' => null,
                ])->save();
                $failed++;
            }
        }

        $digestDeliveries = $due->filter(
            fn (NotificationDelivery $delivery): bool => in_array($delivery->decision, ['digest_daily', 'digest_weekly'], true),
        );

        $groups = $digestDeliveries->groupBy(
            fn (NotificationDelivery $delivery): string => implode(':', [
                $delivery->notifiable_type,
                $delivery->notifiable_id,
                $delivery->channel->value,
                $delivery->locale,
            ]),
        );

        foreach ($groups as $group) {
            if ($this->sendDigest($group)) {
                $digests++;
            } else {
                $failed += $group->count();
            }
        }

        return ['released' => $released, 'digests' => $digests, 'failed' => $failed];
    }

    /** @param Collection<int, NotificationDelivery> $deliveries */
    private function sendDigest(Collection $deliveries): bool
    {
        /** @var NotificationDelivery|null $first */
        $first = $deliveries->first();
        if ($first === null) {
            return false;
        }

        $notifiable = $first->notifiable;
        if (! $notifiable instanceof Model) {
            $this->failSources($deliveries, 'The notification recipient no longer exists.');

            return false;
        }

        if (! in_array($first->channel, [NotificationChannel::Mail, NotificationChannel::Database], true)) {
            foreach ($deliveries as $delivery) {
                try {
                    $this->dispatcher->releaseDeferred($delivery);
                } catch (Throwable $throwable) {
                    $delivery->forceFill([
                        'status' => NotificationDeliveryStatus::Failed,
                        'error' => mb_substr($throwable->getMessage(), 0, 500),
                        'failed_at' => now(),
                        'deferred_until' => null,
                    ])->save();
                }
            }

            return true;
        }

        if ($first->channel === NotificationChannel::Database && ! $notifiable instanceof User) {
            $this->failSources($deliveries, 'Database notification digests require an application user recipient.');

            return false;
        }

        $sourceIds = $deliveries->modelKeys();
        $body = $this->buildBody($deliveries);
        $digest = NotificationDelivery::query()->create([
            'notifiable_type' => $first->notifiable_type,
            'notifiable_id' => $first->notifiable_id,
            'template_key' => 'system.digest',
            'channel' => $first->channel,
            'locale' => $first->locale,
            'route' => $first->route,
            'status' => NotificationDeliveryStatus::Queued,
            'decision' => 'digest_delivery',
            'attempt' => 1,
            'variables' => [
                'source_delivery_ids' => $sourceIds,
                'source_count' => count($sourceIds),
            ],
            'attachments' => [],
            'queued_at' => now(),
        ]);

        $digest = $this->dispatcher->deliverPrepared(
            $digest,
            $notifiable,
            'Notification digest ('.count($sourceIds).')',
            $body,
        );

        return $digest->status !== NotificationDeliveryStatus::Failed;
    }

    /** @param Collection<int, NotificationDelivery> $deliveries */
    private function buildBody(Collection $deliveries): string
    {
        $lines = ['You have '.$deliveries->count().' notifications:'];

        foreach ($deliveries as $delivery) {
            $reference = $delivery->subject_document_id !== null
                ? ' #'.$delivery->subject_document_id
                : '';
            $lines[] = '- '.str((string) $delivery->template_key)->replace('.', ' ')->headline()->toString().$reference;
        }

        return implode("\n", $lines);
    }

    /** @param Collection<int, NotificationDelivery> $deliveries */
    private function failSources(Collection $deliveries, string $message): void
    {
        NotificationDelivery::query()
            ->whereKey($deliveries->modelKeys())
            ->update([
                'status' => NotificationDeliveryStatus::Failed->value,
                'error' => mb_substr($message, 0, 500),
                'failed_at' => now(),
                'deferred_until' => null,
            ]);
    }
}
