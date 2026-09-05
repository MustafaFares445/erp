<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('notifications:visits-due')]
#[Description('Notify the assigned employee once for each visit planned for today.')]
final class SendVisitDueRemindersCommand extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $queued = 0;

        CustomerVisit::query()
            ->where('status', VisitStatus::Planned->value)
            ->whereBetween('planned_at', [today()->startOfDay(), today()->endOfDay()])
            ->with(['employee.user', 'customer'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $visits) use ($dispatcher, &$queued): void {
                foreach ($visits as $visit) {
                    if (! $visit instanceof CustomerVisit) {
                        continue;
                    }

                    $recipient = $visit->employee?->user;
                    if (! $recipient instanceof User) {
                        continue;
                    }
                    if ($this->alreadyAttempted($visit, $recipient)) {
                        continue;
                    }
                    $dispatcher->dispatch(
                        $recipient,
                        NotificationEventKey::VisitDue,
                        [
                            'visit_id' => (string) $visit->getKey(),
                            'customer_name' => (string) ($visit->customer?->company_name ?? 'Customer'),
                            'planned_at' => (string) $visit->planned_at?->format('Y-m-d H:i'),
                        ],
                        $visit,
                        NotificationChannel::Mail,
                    );

                    $queued++;
                }
            });

        $this->components->info(sprintf('Visit-due reminder sweep completed: %d notification(s) queued.', $queued));

        return self::SUCCESS;
    }

    private function alreadyAttempted(CustomerVisit $visit, User $recipient): bool
    {
        return NotificationDelivery::query()
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('subject_document_type', $visit->getMorphClass())
            ->where('subject_document_id', $visit->getKey())
            ->where('template_key', NotificationEventKey::VisitDue->value)
            ->exists();
    }
}
