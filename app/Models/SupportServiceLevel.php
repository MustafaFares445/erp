<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'is_active'])]
final class SupportServiceLevel extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<SupportEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(SupportEntitlement::class);
    }

    /** @return HasMany<SlaPolicy, $this> */
    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class);
    }
}
