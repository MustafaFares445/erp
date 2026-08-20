<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Services\Accounting\ChartOfAccountService;
use Database\Factories\ChartAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the account hierarchy, and the target a journal line posts to.
 *
 * Only leaf accounts may be posting targets (FR-007): a postable parent would
 * mix directly-posted amounts with rolled-up child amounts, and no report could
 * separate them (research.md R-005). That rule, cycle prevention, and
 * deletability all live in {@see ChartOfAccountService} — this model carries no
 * write guard of its own, because unlike a posted journal entry an account has
 * no invariant that must survive a direct write.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §3
 */
/**
 * @property int $id
 * @property int $account_type_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property bool $is_postable
 * @property bool $is_active
 * @property AccountType|null $accountType
 * @property self|null $parent
 * @property Collection<int, self> $children
 * @property Collection<int, JournalEntryLine> $journalEntryLines
 */
#[Fillable([
    'account_type_id',
    'parent_id',
    'code',
    'name',
    'is_postable',
    'is_active',
])]
final class ChartAccount extends Model
{
    /** @use HasFactory<ChartAccountFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return BelongsTo<AccountType, $this> */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<JournalEntryLine, $this> */
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * This account's id plus every descendant's, for the parent balance
     * roll-up in FR-037.
     *
     * Walks the tree level by level rather than recursing per node, so the
     * whole subtree costs one query per depth level regardless of width. A
     * chart of accounts is a handful of levels deep, so this is bounded.
     *
     * The `$seen` set is not redundant with {@see ChartOfAccountService}'s cycle
     * prevention: that guards the write path, and this loop would spin forever
     * on a cycle introduced by a direct database write, which is exactly the
     * situation where an infinite loop is hardest to diagnose.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(): array
    {
        $seen = [$this->id => true];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $children = self::query()
                ->whereIn('parent_id', $frontier)
                ->get(['id'])
                ->map(static fn (self $account): int => $account->id)
                ->reject(static fn (int $id): bool => isset($seen[$id]))
                ->values()
                ->all();

            foreach ($children as $child) {
                $seen[$child] = true;
            }

            $frontier = $children;
        }

        return array_keys($seen);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
