<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\ValidatesCurrencyCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'currency_code', 'is_active'])]
final class PriceList extends Model
{
    use TracksBlameable;
    use ValidatesCurrencyCatalog;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $priceList): void {
            $priceList->validateActiveCurrency('currency_code');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return BelongsToMany<CustomerProfile, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(CustomerProfile::class, 'price_list_customer')->withTimestamps();
    }

    /** @return BelongsToMany<CustomerGroup, $this> */
    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'price_list_customer_group')->withTimestamps();
    }

    /** @param Builder<$this> $query
     * @return Builder<$this>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
