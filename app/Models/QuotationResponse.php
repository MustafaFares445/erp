<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotationResponseType;
use App\Services\Sales\QuotationResponseService;
use Database\Factories\QuotationResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only customer-decision evidence — created only by
 * {@see QuotationResponseService}, never updated.
 */
#[Fillable([
    'quotation_id', 'response_type', 'note', 'responded_by_user_id',
    'recorded_by_user_id', 'responded_at', 'source_channel',
])]
final class QuotationResponse extends Model
{
    /** @use HasFactory<QuotationResponseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'response_type' => QuotationResponseType::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
