<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use Database\Factories\InventoryCorrectionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'correction_number',
    'correction_type',
    'status',
    'original_inventory_operation_id',
    'reason',
    'notes',
    'cancellation_reason',
])]
final class InventoryCorrection extends Model
{
    /** @use HasFactory<InventoryCorrectionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $correction): void {
            $rawOriginalStatus = $correction->getRawOriginal('status');
            $original = is_string($rawOriginalStatus)
                ? InventoryCorrectionStatus::tryFrom($rawOriginalStatus)
                : null;

            if ($original?->isTerminal() === true) {
                throw new DomainException('Posted and cancelled inventory corrections are immutable.');
            }
        });

        self::deleting(function (): never {
            throw new DomainException(
                'Inventory corrections cannot be deleted; cancel a draft or create a new compensating document.',
            );
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'correction_type' => InventoryCorrectionType::class,
            'status' => InventoryCorrectionStatus::class,
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function originalOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'original_inventory_operation_id');
    }

    /** @return HasMany<InventoryCorrectionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCorrectionLine::class);
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

    public function isDraft(): bool
    {
        return $this->status === InventoryCorrectionStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === InventoryCorrectionStatus::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === InventoryCorrectionStatus::Cancelled;
    }
}
