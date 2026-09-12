<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentLinkStatus;
use Database\Factories\TicketPaymentLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_id',
    'amount',
    'currency',
    'status',
    'external_payment_reference',
    'payment_url',
    'payment_method_reference',
    'payment_method_id',
    'invoice_id',
    'payment_id',
    'settled_by',
    'settled_at',
])]
final class TicketPaymentLink extends Model
{
    /** @use HasFactory<TicketPaymentLinkFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentLinkStatus::class,
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
