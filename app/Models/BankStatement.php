<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['payment_method_id', 'currency_code', 'period_start', 'period_end', 'opening_balance', 'closing_balance'])]
final class BankStatement extends Model
{
    protected $attributes = ['status' => 'open'];

    #[\Override]
    public function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'imported_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
