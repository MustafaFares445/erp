<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeArticle */
final class KnowledgeArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'body' => $this->body,
            'locale' => $this->locale,
            'category' => $this->category === null ? null : [
                'id' => $this->category->getKey(),
                'name' => $this->category->name,
            ],
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
