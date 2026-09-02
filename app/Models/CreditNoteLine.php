<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CreditNoteLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_line_id', 'description', 'quantity', 'unit_price', 'tax_amount', 'line_total', 'sort_order'])]
final class CreditNoteLine extends Model
{
    /** @use HasFactory<CreditNoteLineFactory> */
    use HasFactory;

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            if ($line->creditNote()->whereNotNull('confirmed_at')->exists()) {
                throw new \DomainException('A confirmed credit note line is immutable.');
            }
        });

        self::deleting(function (self $line): void {
            if ($line->creditNote()->whereNotNull('confirmed_at')->exists()) {
                throw new \DomainException('A confirmed credit note line cannot be deleted.');
            }
        });
    }
}
