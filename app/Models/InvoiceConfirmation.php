<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['confirmed_by_user_id', 'confirmation_type', 'confirmed_at', 'notes'])]
final class InvoiceConfirmation extends Model implements HasMedia
{
    /** @use HasFactory<InvoiceConfirmationFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice-confirmation-signature')->useDisk('local');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new \DomainException('Invoice confirmations are append-only.');
        });

        self::deleting(static function (): never {
            throw new \DomainException('Invoice confirmations are append-only.');
        });
    }
}
