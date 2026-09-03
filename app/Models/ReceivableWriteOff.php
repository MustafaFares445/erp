<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Models\Concerns\TransitionsDocumentStatus;
use Database\Factories\ReceivableWriteOffFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'write_off_number',
    'status',
    'customer_id',
    'invoice_id',
    'amount_minor',
    'tax_amount_minor',
    'reason_category',
    'reason',
    'fiscal_period_id',
])]
final class ReceivableWriteOff extends Model
{
    /** @use HasFactory<ReceivableWriteOffFactory> */
    use HasFactory;

    use SoftDeletes;
    use TransitionsDocumentStatus;

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<FiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function isDraft(): bool
    {
        return $this->status === WriteOffStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === WriteOffStatus::Approved;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => WriteOffStatus::class,
            'reason_category' => WriteOffReason::class,
            'amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(static function (self $writeOff): void {
            $original = $writeOff->getRawOriginal('status');

            if (is_string($original) && $original !== WriteOffStatus::Draft->value) {
                throw new DomainException('An approved or cancelled write-off is immutable.');
            }
        });

        self::deleting(static function (self $writeOff): void {
            if (! $writeOff->isDraft()) {
                throw new DomainException('An approved write-off cannot be deleted.');
            }
        });
    }
}
