<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RefundStatus;
use App\Enums\UserType;
use App\Enums\WriteOffStatus;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\NotificationDelivery;
use App\Models\PurchaseOrder;
use App\Models\ReceivableWriteOff;
use App\Models\Refund;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

#[Signature('notifications:pending-approvals')]
#[Description('Notify administrators once for documents currently waiting at an approval boundary.')]
final class SendPendingApprovalRemindersCommand extends Command
{
    public function handle(NotificationDispatcher $dispatcher): int
    {
        $admins = User::query()
            ->where('user_type', UserType::Admin->value)
            ->orderBy('id')
            ->get();

        $queued = 0;

        foreach ($this->pendingDocuments() as [$document, $type, $number]) {
            foreach ($admins as $admin) {
                if (! $admin instanceof User || $this->alreadyAttempted($document, $admin)) {
                    continue;
                }

                $dispatcher->dispatch(
                    $admin,
                    NotificationEventKey::ApprovalPending,
                    [
                        'document_type' => $type,
                        'document_number' => $number,
                    ],
                    $document,
                    NotificationChannel::Mail,
                );

                $queued++;
            }
        }

        $this->components->info(sprintf('Pending-approval reminder sweep completed: %d notification(s) queued.', $queued));

        return self::SUCCESS;
    }

    /** @return list<array{Model,string,string}> */
    private function pendingDocuments(): array
    {
        $documents = [];

        foreach (PurchaseOrder::query()->where('status', PurchaseOrderStatus::PendingApproval->value)->get() as $document) {
            $documents[] = [$document, 'Purchase order', (string) $document->purchase_order_number];
        }

        // These accounting document families use Draft as their maker/checker approval boundary.
        foreach (Bill::query()->where('status', BillStatus::Draft->value)->get() as $document) {
            $documents[] = [$document, 'Bill', (string) $document->bill_number];
        }

        foreach (Expense::query()->where('status', ExpenseStatus::Draft->value)->get() as $document) {
            $documents[] = [$document, 'Expense', (string) $document->expense_number];
        }

        foreach (Refund::query()->where('status', RefundStatus::Draft->value)->get() as $document) {
            $documents[] = [$document, 'Refund', (string) $document->refund_number];
        }

        foreach (ReceivableWriteOff::query()->where('status', WriteOffStatus::Draft->value)->get() as $document) {
            $documents[] = [$document, 'Receivable write-off', (string) $document->write_off_number];
        }

        return $documents;
    }

    private function alreadyAttempted(Model $document, User $recipient): bool
    {
        return NotificationDelivery::query()
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('subject_document_type', $document->getMorphClass())
            ->where('subject_document_id', $document->getKey())
            ->where('template_key', NotificationEventKey::ApprovalPending->value)
            ->exists();
    }
}
