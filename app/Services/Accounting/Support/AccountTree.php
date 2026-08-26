<?php

declare(strict_types=1);

namespace App\Services\Accounting\Support;

use App\Enums\NormalBalance;
use App\Models\ChartAccount;
use App\Services\Accounting\AccountBalanceService;
use Illuminate\Database\Eloquent\Collection;

/**
 * The chart of accounts as an in-memory hierarchy, loaded once per report.
 *
 * A second copy of the parent/children walk
 * {@see AccountBalanceService} already carries,
 * deliberately not shared (research §R6, FR-054): this class additionally
 * needs depth-first display ordering, which `AccountBalanceService` has no
 * reason to grow.
 *
 * @see /specs/020-accounting-financial-reports/data-model.md §AccountTree
 */
final readonly class AccountTree
{
    /** @var Collection<int, ChartAccount> */
    private Collection $accounts;

    /** @var array<int, list<int>> */
    private array $childrenOf;

    public function __construct()
    {
        // withTrashed(): a soft-deleted account's posted history still belongs on
        // every statement (FR-018), and its hierarchy position must stay intact
        // even when it is itself the ancestor of a still-active account.
        /** @var Collection<int, ChartAccount> $accounts */
        $accounts = ChartAccount::query()->withTrashed()->with('accountType')->get()->keyBy('id');

        $this->accounts = $accounts;

        $childrenOf = [];

        foreach ($this->accounts as $account) {
            $parentId = $account->parent_id;

            if ($parentId !== null) {
                $childrenOf[$parentId][] = $account->id;
            }
        }

        $this->childrenOf = $childrenOf;
    }

    /**
     * Depth-first: each account immediately followed by its descendants,
     * siblings ordered by `code` as a string (research §R5) — computed here
     * in memory rather than as a SQL `ORDER BY`, so a three-character code
     * sorts correctly next to a four-character one.
     *
     * @return list<ChartAccount>
     */
    public function displayOrder(): array
    {
        $roots = $this->siblingsSortedByCode(
            $this->accounts->whereNull('parent_id')->all()
        );

        $ordered = [];
        $visited = [];

        foreach ($roots as $root) {
            $this->appendDepthFirst($root, $ordered, $visited);
        }

        return $ordered;
    }

    /**
     * Sums an account's own value with its whole subtree's (FR-014).
     *
     * `$visited` guards against a cycle introduced by a direct database
     * write, the same reason
     * {@see AccountBalanceService::rollUp()} and
     * {@see ChartAccount::selfAndDescendantIds()} carry the same guard
     * (FR-015).
     *
     * @param  callable(int): int  $ownValue
     * @param  array<int, true>  $visited
     */
    public function rollUp(int $accountId, callable $ownValue, array $visited = []): int
    {
        if (isset($visited[$accountId])) {
            return 0;
        }

        $visited[$accountId] = true;
        $total = $ownValue($accountId);

        foreach ($this->childIdsOf($accountId) as $childId) {
            $total += $this->rollUp($childId, $ownValue, $visited);
        }

        return $total;
    }

    /** @return list<int> */
    public function childIdsOf(int $accountId): array
    {
        return $this->childrenOf[$accountId] ?? [];
    }

    /**
     * The account itself, from the collection already loaded once by the
     * constructor — no further query.
     */
    public function accountById(int $accountId): ?ChartAccount
    {
        return $this->accounts->get($accountId);
    }

    /**
     * `1` for a debit-normal account, `-1` for a credit-normal one, falling
     * back to debit-normal for an id this tree does not hold — the same
     * degradation {@see AccountBalanceService::signFor()}
     * applies when an account type is somehow missing.
     */
    public function signOf(int $accountId): int
    {
        return $this->accountById($accountId)?->accountType?->normal_balance?->sign() ?? NormalBalance::Debit->sign();
    }

    /**
     * How many ancestors separate this account from a root (`0` for a root
     * itself), walked from the collection already in memory. `$visited`
     * guards the same cycle case every other walk in this class does.
     *
     * @param  array<int, true>  $visited
     */
    public function depthOf(int $accountId, array $visited = []): int
    {
        if (isset($visited[$accountId])) {
            return 0;
        }

        $visited[$accountId] = true;
        $parentId = $this->accountById($accountId)?->parent_id;

        if ($parentId === null || ! $this->accounts->has($parentId)) {
            return 0;
        }

        return 1 + $this->depthOf($parentId, $visited);
    }

    /**
     * @param  array<int, true>  $visited
     * @return list<int>
     */
    public function selfAndDescendantIds(int $accountId, array $visited = []): array
    {
        if (isset($visited[$accountId])) {
            return [];
        }

        $visited[$accountId] = true;
        $ids = [$accountId];

        foreach ($this->childIdsOf($accountId) as $childId) {
            array_push($ids, ...$this->selfAndDescendantIds($childId, $visited));
        }

        return $ids;
    }

    /**
     * @param  list<ChartAccount>  $ordered
     * @param  array<int, true>  $visited
     */
    private function appendDepthFirst(ChartAccount $account, array &$ordered, array &$visited): void
    {
        if (isset($visited[$account->id])) {
            return;
        }

        $visited[$account->id] = true;
        $ordered[] = $account;

        $children = $this->siblingsSortedByCode(
            array_values(array_filter(array_map(
                fn (int $id): ?ChartAccount => $this->accounts->get($id),
                $this->childIdsOf($account->id),
            )))
        );

        foreach ($children as $child) {
            $this->appendDepthFirst($child, $ordered, $visited);
        }
    }

    /**
     * @param  array<int, ChartAccount>|list<ChartAccount>  $accounts
     * @return list<ChartAccount>
     */
    private function siblingsSortedByCode(array $accounts): array
    {
        $sorted = array_values($accounts);

        // strcmp(), not <=>: PHP's spaceship operator compares two numeric-
        // looking strings numerically rather than byte-by-byte, which is
        // exactly the "sorts as a number" bug research §R5 exists to avoid —
        // '900' must sort after '1000' as text, not before it as a number.
        usort($sorted, static fn (ChartAccount $a, ChartAccount $b): int => strcmp($a->code, $b->code));

        return $sorted;
    }
}
