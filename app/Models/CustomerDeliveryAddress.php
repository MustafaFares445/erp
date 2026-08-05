<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\CustomerDeliveryAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_profile_id', 'label', 'address', 'country', 'city', 'latitude', 'longitude',
    'contact_name', 'contact_phone', 'is_active', 'is_default',
])]
final class CustomerDeliveryAddress extends Model
{
    /** @use HasFactory<CustomerDeliveryAddressFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
