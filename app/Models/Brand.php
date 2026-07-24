<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'name_ar', 'code', 'is_active'])]
final class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
