<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerPricingTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_user_id', 'pricing_tier_id', 'is_active'])]
final class CustomerPricingTier extends Model
{
    /** @use HasFactory<CustomerPricingTierFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<PricingTier, $this> */
    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class);
    }
}
