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
    'settled_by',
    'settled_at',
])]
final class TicketPaymentLink extends Model
{
    /** @use HasFactory<TicketPaymentLinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentLinkStatus::class,
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
