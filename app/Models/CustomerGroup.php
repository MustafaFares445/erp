<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'is_active'])]
final class CustomerGroup extends Model
{
    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<CustomerProfile, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(CustomerProfile::class);
    }

    /** @return BelongsToMany<PriceList, $this> */
    public function priceLists(): BelongsToMany
    {
        return $this->belongsToMany(PriceList::class, 'price_list_customer_group')->withTimestamps();
    }
}
