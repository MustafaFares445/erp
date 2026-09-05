<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\UserType;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('notifications:expiring-lots')]
#[Description('Notify administrators once when a physical inventory lot enters the expiry-alert window.')]
final class SendExpiringLotRemindersCommand extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $admins = User::query()
            ->where('user_type', UserType::Admin->value)
            ->orderBy('id')
            ->get();

        if ($admins->isEmpty()) {
            $this->components->info('Expiring-lot reminder sweep completed: no administrator recipients.');

            return self::SUCCESS;
        }

        $queued = 0;
        $threshold = today()->addDays(InventorySetting::expiryAlertDays());

        InventoryLot::query()
            ->canonical()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', $threshold)
            ->with('conditionBalances')
            ->orderBy('id')
            ->chunkById(200, function (Collection $lots) use ($admins, $dispatcher, &$queued): void {
                foreach ($lots as $lot) {
                    if (! $lot instanceof InventoryLot) {
                        continue;
                    }
                    if ($lot->totalPhysicalQuantity() <= 0) {
                        continue;
                    }
                    foreach ($admins as $admin) {
                        if (! $admin instanceof User) {
                            continue;
                        }
                        if ($this->alreadyAttempted($lot, $admin)) {
                            continue;
                        }
                        $dispatcher->dispatch(
                            $admin,
                            NotificationEventKey::LotExpiring,
                            [
                                'lot_number' => (string) ($lot->lot_number ?? '#'.$lot->getKey()),
                                'expires_at' => (string) $lot->expires_at?->toDateString(),
                            ],
                            $lot,
                            NotificationChannel::Mail,
                        );

                        $queued++;
                    }
                }
            });

        $this->components->info(sprintf('Expiring-lot reminder sweep completed: %d notification(s) queued.', $queued));

        return self::SUCCESS;
    }

    private function alreadyAttempted(InventoryLot $lot, User $recipient): bool
    {
        return NotificationDelivery::query()
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('subject_document_type', $lot->getMorphClass())
            ->where('subject_document_id', $lot->getKey())
            ->where('template_key', NotificationEventKey::LotExpiring->value)
            ->exists();
    }
}
