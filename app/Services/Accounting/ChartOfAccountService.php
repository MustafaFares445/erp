<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\ChartAccount;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountHierarchyCycle;
use App\Services\Accounting\Exceptions\AccountNotDeletable;
use App\Services\Accounting\Exceptions\AccountNotPostable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Chart of accounts writes, and the three structural rules that keep the
 * hierarchy usable.
 *
 * Only leaf accounts may be posting targets (FR-007): a postable parent would mix
 * directly-posted amounts with rolled-up child amounts, and no report could
 * separate them (research.md R-005). An account carrying posted history may
 * always be marked inactive but never deleted (FR-010, FR-011) — inactive blocks
 * future postings without rewriting the past.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §3
 */
final readonly class ChartOfAccountService
{
    /**
     * @param  array{account_type_id: int, code: string, name: string, parent_id?: int|null, is_postable?: bool, is_active?: bool}  $attributes
     */
    public function create(User $actor, array $attributes): ChartAccount
    {
        Gate::forUser($actor)->authorize('create', ChartAccount::class);

        return DB::transaction(function () use ($actor, $attributes): ChartAccount {
            $parentId = $attributes['parent_id'] ?? null;

            $account = new ChartAccount([
                'account_type_id' => $attributes['account_type_id'],
                'parent_id' => $parentId,
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'is_postable' => $attributes['is_postable'] ?? true,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            $account->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->demoteParentOf($account);

            return $account->refresh();
        });
    }

    /**
     * @param  array{account_type_id?: int, code?: string, name?: string, parent_id?: int|null, is_postable?: bool, is_active?: bool}  $attributes
     */
    public function update(User $actor, ChartAccount $account, array $attributes): ChartAccount
    {
        Gate::forUser($actor)->authorize('update', $account);

        return DB::transaction(function () use ($actor, $account, $attributes): ChartAccount {
            if (array_key_exists('parent_id', $attributes)) {
                $this->guardAgainstCycle($account, $attributes['parent_id']);
            }

            if (($attributes['is_postable'] ?? null) === true) {
                $childCount = $account->children()->count();

                if ($childCount > 0) {
                    throw AccountNotPostable::hasChildren((string) $account->code, $childCount);
                }
            }

            $account->update([...$attributes, 'updated_by' => $actor->getKey()]);

            $this->demoteParentOf($account->refresh());

            return $account->refresh();
        });
    }

    public function delete(User $actor, ChartAccount $account): void
    {
        Gate::forUser($actor)->authorize('delete', $account);

        $childCount = $account->children()->count();

        if ($childCount > 0) {
            throw AccountNotDeletable::hasChildren((string) $account->code, $childCount);
        }

        // Draft lines count too: deleting the account they point at would break
        // the draft rather than usefully tidying anything (FR-010).
        $lineCount = $account->journalEntryLines()->count();

        if ($lineCount > 0) {
            throw AccountNotDeletable::hasJournalLines((string) $account->code, $lineCount);
        }

        $account->delete();
    }

    /**
     * Clears `is_postable` on the parent that just gained a child (FR-008).
     *
     * Done automatically rather than refused, because adding a sub-account to a
     * previously-postable account is a normal thing to want and the parent simply
     * stops being a posting target at that moment.
     */
    private function demoteParentOf(ChartAccount $account): void
    {
        $parent = $account->parent;

        if ($parent instanceof ChartAccount && $parent->is_postable) {
            $parent->update(['is_postable' => false]);
        }
    }

    /**
     * Refuses a parent that is the account itself or one of its descendants
     * (FR-006).
     *
     * Checked against {@see ChartAccount::selfAndDescendantIds()}, the same walk
     * the balance roll-up uses, so the two can never disagree about what a
     * descendant is.
     */
    private function guardAgainstCycle(ChartAccount $account, int|string|null $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parentId = (int) $parentId;

        if (in_array($parentId, $account->selfAndDescendantIds(), true)) {
            $parentCode = ChartAccount::query()->whereKey($parentId)->value('code');

            // Named by id if the row has vanished between the check and the read,
            // so the refusal still identifies which parent was rejected.
            throw AccountHierarchyCycle::between(
                $account->code,
                is_string($parentCode) ? $parentCode : '#'.$parentId,
            );
        }
    }
}
