<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'is_active'])]
final class KnowledgeArticleCategory extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<KnowledgeArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class, 'category_id');
    }
}
