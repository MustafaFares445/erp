<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceChangeRequestStatus;
use Database\Factories\PriceHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['product_variant_id', 'cost_price', 'base_price', 'min_price', 'markup_percent', 'changed_by', 'status', 'reviewed_by', 'reviewed_at'])]
final class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $history): void {
            $original = $history->getRawOriginal('status');

            if ($original !== null && $original !== PriceChangeRequestStatus::Pending->value) {
                throw new LogicException('A reviewed price change request is immutable.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Price change requests cannot be deleted.');
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'status' => PriceChangeRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
