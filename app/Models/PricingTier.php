<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\PricingTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'tier_type', 'discount_type', 'discount_value', 'customer_user_id', 'visibility', 'valid_from', 'valid_until', 'is_active'])]
final class PricingTier extends Model
{
    /** @use HasFactory<PricingTierFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'tier_type' => PricingTierType::class,
            'discount_type' => PricingTierDiscountType::class,
            'discount_value' => 'decimal:2',
            'visibility' => PricingTierVisibility::class,
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return HasMany<CustomerPricingTier, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerPricingTier::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'pricing_tier_products')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<PricingTier>  $query
     * @return Builder<PricingTier>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $dateQuery): Builder => $dateQuery
                ->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', today()))
            ->where(fn (Builder $dateQuery): Builder => $dateQuery
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', today()));
    }

    /**
     * @param  Builder<PricingTier>  $query
     * @return Builder<PricingTier>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereDate('valid_from', '>', today());
    }

    /**
     * @param  Builder<PricingTier>  $query
     * @return Builder<PricingTier>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereDate('valid_until', '<', today());
    }

    public function status(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }

        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->tier_type === PricingTierType::ProductScoped && $this->valid_from?->isAfter(today())) {
            return 'scheduled';
        }

        if ($this->tier_type === PricingTierType::ProductScoped && $this->valid_until?->isBefore(today())) {
            return 'expired';
        }

        return 'active';
    }

    public function getStatusAttribute(): string
    {
        return $this->status();
    }
}
