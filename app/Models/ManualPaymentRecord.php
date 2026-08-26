<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ManualPaymentRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'received_at'])]
final class ManualPaymentRecord extends Model
{
    /** @use HasFactory<ManualPaymentRecordFactory> */
    use HasFactory;

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
