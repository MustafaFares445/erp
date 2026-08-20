<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountElement;
use App\Enums\NormalBalance;
use Database\Factories\AccountTypeFactory;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exactly five seeded rows, one per {@see AccountElement} (FR-002).
 *
 * Seeded by {@see ChartOfAccountsSeeder} and exposed through no Filament
 * resource at all: the five accounting elements are fixed by double-entry
 * accounting, so there is no field here an operator could legitimately tune
 * (research.md R-007). Surfaced as a column and filter on Chart of Accounts.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §2
 */
/**
 * @property int $id
 * @property AccountElement $name
 * @property NormalBalance $normal_balance
 * @property Collection<int, ChartAccount> $accounts
 */
#[Fillable([
    'name',
    'normal_balance',
])]
final class AccountType extends Model
{
    /** @use HasFactory<AccountTypeFactory> */
    use HasFactory;

    /** @return HasMany<ChartAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ChartAccount::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'name' => AccountElement::class,
            'normal_balance' => NormalBalance::class,
        ];
    }
}
