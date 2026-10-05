<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QualityResolutionType;
use Database\Factories\TicketQualityResolutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The recorded outcome of a product quality complaint. Support records the
 * decision; returns, refunds and credit notes follow the existing Inventory
 * and Sales paths.
 *
 * @property int $id
 * @property int $ticket_id
 */
#[Fillable([
    'ticket_id',
    'resolution_type',
    'notes',
    'customer_return_request_id',
    'supplier_id',
    'resolved_by',
    'resolved_at',
])]
final class TicketQualityResolution extends Model
{
    /** @use HasFactory<TicketQualityResolutionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'resolution_type' => QualityResolutionType::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<CustomerReturnRequest, $this> */
    public function customerReturnRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnRequest::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
