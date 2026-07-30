<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Models\Concerns\TracksBlameable;
use App\Observers\ProductSubscriptionObserver;
use Database\Factories\ProductSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'discount_type', 'discount_value', 'visibility', 'is_active', 'valid_from', 'valid_until'])]
#[ObservedBy(ProductSubscriptionObserver::class)]
final class ProductSubscription extends Model
{
    /** @use HasFactory<ProductSubscriptionFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'discount_type' => ProductSubscriptionDiscountType::class,
            'discount_value' => 'decimal:2',
            'visibility' => ProductSubscriptionVisibility::class,
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_subscription_products')->withTimestamps();
    }

    /** @return BelongsToMany<CustomerProfile, $this> */
    public function customerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(CustomerProfile::class, 'customer_product_subscriptions')->withTimestamps();
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
     * @param  Builder<ProductSubscription>  $query
     * @return Builder<ProductSubscription>
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
     * @param  Builder<ProductSubscription>  $query
     * @return Builder<ProductSubscription>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('valid_from', '>', today());
    }

    /**
     * @param  Builder<ProductSubscription>  $query
     * @return Builder<ProductSubscription>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('valid_until', '<', today());
    }

    public function status(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }

        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->valid_from?->isAfter(today())) {
            return 'scheduled';
        }

        if ($this->valid_until?->isBefore(today())) {
            return 'expired';
        }

        return 'active';
    }

    public function getStatusAttribute(): string
    {
        return $this->status();
    }
}
