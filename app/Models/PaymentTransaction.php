<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionSettlementState;
use App\Enums\PaymentTransactionStatus;
use App\Services\Support\TicketPaymentService;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Provider-confirmed transactions that have not reached their purpose's canonical settlement.
     * Support ticket payments settle to their ticket link; other purposes require a linked ERP Payment.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRequiresSettlementAttention(Builder $query): Builder
    {
        return $query->where('status', PaymentTransactionStatus::Succeeded->value)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('purpose_type', TicketPaymentLink::class)
                        ->where(function (Builder $ticketPurpose): void {
                            $ticketPurpose->whereDoesntHaveMorph('purpose', [TicketPaymentLink::class])
                                ->orWhereHasMorph('purpose', [TicketPaymentLink::class], fn (Builder $purpose): Builder => $purpose
                                    ->where('status', '!=', PaymentLinkStatus::Settled->value));
                        });
                })->orWhere(function (Builder $query): void {
                    $query->where('purpose_type', '!=', TicketPaymentLink::class)
                        ->where(function (Builder $query): void {
                            $query->whereNull('payment_id')
                                ->orWhereDoesntHave('payment');
                        });
                });
            });
    }

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

    /**
     * Ticket-purpose transactions settle through {@see TicketPaymentService},
     * which now posts the collected amount as a canonical ERP {@see Payment}
     * and customer deposit before the ticket becomes live. The ticket link
     * remains the purpose-level source of truth while `payment_id` provides
     * the accounting trace back to the posted collection.
     */
    public function isSettled(): bool
    {
        return $this->settlementState() === PaymentTransactionSettlementState::Settled;
    }

    public function settlementState(): PaymentTransactionSettlementState
    {
        $purpose = $this->purpose;

        if ($purpose instanceof TicketPaymentLink) {
            return $purpose->status === PaymentLinkStatus::Settled
                ? PaymentTransactionSettlementState::Settled
                : ($this->status === PaymentTransactionStatus::Succeeded
                    ? PaymentTransactionSettlementState::RequiresAttention
                    : self::stateFromProviderStatus($this->status));
        }

        if (in_array($this->status, [PaymentTransactionStatus::Failed, PaymentTransactionStatus::Cancelled], true)) {
            return self::stateFromProviderStatus($this->status);
        }

        if (in_array($this->status, [PaymentTransactionStatus::Pending, PaymentTransactionStatus::RequiresAction], true)) {
            return PaymentTransactionSettlementState::WaitingForProvider;
        }

        return $this->payment_id !== null && $this->payment instanceof Payment
            ? PaymentTransactionSettlementState::Settled
            : PaymentTransactionSettlementState::RequiresAttention;
    }

    public function settlementDescription(): string
    {
        $state = $this->settlementState();

        if ($this->purpose instanceof TicketPaymentLink) {
            if ($state === PaymentTransactionSettlementState::Settled) {
                return __('admin.payments.settlement_state_description.settled_ticket');
            }

            if ($state === PaymentTransactionSettlementState::RequiresAttention) {
                return __('admin.payments.settlement_state_description.requires_attention_ticket');
            }
        }

        return $state->description();
    }

    public function purposeLabel(): string
    {
        return match (true) {
            $this->purpose instanceof Invoice => __('admin.sales.payment_ui.invoice_purpose', [
                'number' => $this->purpose->invoice_number,
            ]),
            $this->purpose instanceof Order => __('admin.sales.payment_ui.order_purpose', [
                'number' => $this->purpose->order_number,
            ]),
            $this->purpose instanceof TicketPaymentLink && $this->purpose->ticket !== null => __('admin.sales.payment_ui.ticket_purpose', [
                'number' => $this->purpose->ticket->ticket_number,
            ]),
            $this->purpose instanceof TicketPaymentLink => __('admin.sales.payment_ui.ticket_purpose_unavailable'),
            default => __('admin.sales.payment_ui.purpose_unavailable'),
        };
    }

    private static function stateFromProviderStatus(PaymentTransactionStatus $status): PaymentTransactionSettlementState
    {
        return match ($status) {
            PaymentTransactionStatus::Failed => PaymentTransactionSettlementState::Failed,
            PaymentTransactionStatus::Cancelled => PaymentTransactionSettlementState::Cancelled,
            PaymentTransactionStatus::Pending, PaymentTransactionStatus::RequiresAction => PaymentTransactionSettlementState::WaitingForProvider,
            PaymentTransactionStatus::Succeeded, PaymentTransactionStatus::PartiallyRefunded, PaymentTransactionStatus::Refunded => PaymentTransactionSettlementState::RequiresAttention,
        };
    }
}
