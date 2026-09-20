<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Provider-side payment lifecycle evidence — independent of
 * {@see Payment}. See the migration docblock for the settlement invariant.
 */
#[Fillable([
    'customer_id', 'payment_id', 'provider', 'purpose_type', 'purpose_id', 'provider_customer_reference',
    'checkout_session_id', 'payment_intent_id', 'provider_charge_id', 'amount_minor', 'currency', 'status',
    'idempotency_key', 'last_provider_event_id', 'failure_code', 'failure_message',
    'succeeded_at', 'cancelled_at', 'refunded_at', 'metadata',
])]
final class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentTransactionStatus::class,
            'amount_minor' => 'integer',
            'succeeded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return MorphTo<Model, $this> */
    public function purpose(): MorphTo
    {
        return $this->morphTo();
    }

    public function amount(): float
    {
        return $this->amount_minor / 100;
    }

    public function isSettled(): bool
    {
        return $this->payment_id !== null;
    }
}
