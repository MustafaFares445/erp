<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('notifications:retry-failed')]
#[Description('Re-queue failed notification deliveries that are still below the retry cap.')]
final class RetryFailedNotificationsCommand extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $retried = 0;
        $failed = 0;

        NotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::Failed->value)
            ->where('attempt', '<', 3)
            ->orderBy('id')
            ->chunkById(250, function (Collection $deliveries) use ($dispatcher, &$retried, &$failed): void {
                foreach ($deliveries as $delivery) {
                    try {
                        $dispatcher->retry($delivery);
                        $retried++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->components->error(sprintf(
                            'Notification delivery #%d failed to re-queue: %s',
                            $delivery->getKey(),
                            $exception->getMessage(),
                        ));
                    }
                }
            });

        $this->components->info(sprintf(
            'Notification retry sweep completed: %d re-queued, %d failed.',
            $retried,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
