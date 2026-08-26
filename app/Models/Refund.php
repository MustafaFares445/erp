<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $refund_number
 * @property int $customer_id
 * @property int $payment_method_id
 * @property Carbon $refund_date
 * @property string $amount
 * @property string $status
 */
#[Fillable(['refund_number', 'customer_id', 'payment_method_id', 'refund_date', 'amount', 'reason', 'status'])]
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = [
        'status' => 'draft',
    ];

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'refund_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
