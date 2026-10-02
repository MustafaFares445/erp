<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseAgreementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'currency_code', 'starts_on', 'ends_on', 'notes'])]
final class PurchaseAgreement extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => PurchaseAgreementStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseAgreementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseAgreementLine::class);
    }
}
