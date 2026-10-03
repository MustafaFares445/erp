<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['bank_statement_line_id', 'matchable_type', 'matchable_id', 'amount', 'matched_by', 'matched_at', 'notes'])]
final class BankReconciliationMatch extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['amount' => 'decimal:2', 'matched_at' => 'datetime'];
    }

    /** @return BelongsTo<BankStatementLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    /** @return MorphTo<Model, $this> */
    public function matchable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
