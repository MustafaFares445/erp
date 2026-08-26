<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\JournalEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;
use App\Services\Accounting\JournalPostingService;

/**
 * Journal entry authorization.
 *
 * Two things here are not permission checks and cannot be granted by any role:
 * `update` and `delete` are refused outright for a posted entry, because
 * immutability is an invariant rather than a privilege (FR-025,
 * permissions.md R-1). The only route to changing a posted entry's effect is
 * `reverse`.
 *
 * `post` and `reverse` are separate abilities (FR-040): posting adds to the
 * ledger, while reversing changes the meaning of figures that may already have
 * been reported.
 *
 * {@see JournalPostingService::draft()} and
 * {@see JournalPostingService::post()} authorize
 * `create`/`post` when the entry carries no `source`, and
 * `createFromSource`/`postFromSource` when it does (spec 019, ADR 0008). This
 * is what lets a Sales role hold `accounting.journal-entry.post-from-source`
 * — enough to let their own document post — without also holding
 * `JournalEntryManage`, which would unlock this page's free-form "New Journal
 * Entry" action.
 */
final class JournalEntryPolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'create');
    }

    public function createFromSource(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'createFromSource');
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        if ($entry->isPosted()) {
            return false;
        }

        return $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        if ($entry->isPosted()) {
            return false;
        }

        return $this->authorizeAccountingAbility($user, 'delete');
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        if ($entry->isPosted()) {
            return false;
        }

        return $this->authorizeAccountingAbility($user, $entry->source_type !== null ? 'postFromSource' : 'post');
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        if (! $entry->isPosted()) {
            return false;
        }

        return $this->authorizeAccountingAbility($user, 'reverse');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::JournalEntryView->value,
            'view' => AccountingPermission::JournalEntryView->value,
            'create' => AccountingPermission::JournalEntryManage->value,
            'createFromSource' => AccountingPermission::JournalEntryPostFromSource->value,
            'update' => AccountingPermission::JournalEntryManage->value,
            'delete' => AccountingPermission::JournalEntryManage->value,
            'post' => AccountingPermission::JournalEntryPost->value,
            'postFromSource' => AccountingPermission::JournalEntryPostFromSource->value,
            'reverse' => AccountingPermission::JournalEntryReverse->value,
        ];
    }
}
