<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sales\OrderCompletionService;
use Database\Factories\OrderCompletionConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Append-only final-completion evidence — created only by
 * {@see OrderCompletionService}, never updated.
 */
#[Fillable(['order_id', 'customer_id', 'confirmed_at', 'source_channel', 'note'])]
final class OrderCompletionConfirmation extends Model
{
    /** @use HasFactory<OrderCompletionConfirmationFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('order-completion-evidence')->useDisk('local');
    }
}
