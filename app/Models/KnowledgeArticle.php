<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\TicketType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'category_id',
    'title',
    'slug',
    'summary',
    'body',
    'visibility',
    'status',
    'locale',
    'published_at',
    'author_id',
    'updated_by',
])]
final class KnowledgeArticle extends Model
{
    use SoftDeletes;

    #[\Override]
    public function casts(): array
    {
        return [
            'visibility' => KnowledgeArticleVisibility::class,
            'status' => KnowledgeArticleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KnowledgeArticleCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticleCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsToMany<ProductVariant, $this> */
    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'knowledge_article_product_variants');
    }

    /** @return HasMany<TicketKnowledgeArticle, $this> */
    public function ticketLinks(): HasMany
    {
        return $this->hasMany(TicketKnowledgeArticle::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function publishedForCustomers(Builder $query, ?string $locale = null): Builder
    {
        return $query
            ->where('status', KnowledgeArticleStatus::Published->value)
            ->whereIn('visibility', KnowledgeArticleVisibility::customerVisibleValues())
            ->when(
                $locale !== null,
                static fn (Builder $query): Builder => $query->whereIn('locale', array_values(array_unique([$locale, 'en']))),
            );
    }

    /** @return list<TicketType> */
    public function ticketTypes(): array
    {
        $types = [];

        foreach (DB::table('knowledge_article_ticket_types')
            ->where('knowledge_article_id', $this->getKey())
            ->pluck('ticket_type') as $value) {
            $type = is_string($value) ? TicketType::tryFrom($value) : null;

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
