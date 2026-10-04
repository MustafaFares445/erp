<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['bank_statement_id', 'sequence', 'line_hash', 'transaction_date', 'amount', 'reference', 'counterparty', 'description'])]
final class BankStatementLine extends Model
{
    protected $attributes = ['status' => 'unmatched'];

    #[\Override]
    public function casts(): array
    {
        return ['sequence' => 'integer', 'transaction_date' => 'date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<BankStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** @return HasMany<BankReconciliationMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function amountMinor(): int
    {
        return JournalEntryLine::toMinorUnits($this->amount);
    }

    public function matchedMinor(): int
    {
        return $this->matches->sum(fn (BankReconciliationMatch $match): int => JournalEntryLine::toMinorUnits($match->amount));
    }

    public function remainingMinor(): int
    {
        return max(0, abs($this->amountMinor()) - $this->matchedMinor());
    }
}
