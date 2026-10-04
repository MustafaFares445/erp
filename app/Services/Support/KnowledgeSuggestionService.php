<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Models\KnowledgeArticle;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KnowledgeSuggestionService
{
    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function suggestForTicket(
        Ticket $ticket,
        int $limit = 5,
        bool $customerFacing = false,
        ?string $locale = null,
    ): Collection {
        $query = KnowledgeArticle::query()
            ->where('status', KnowledgeArticleStatus::Published->value)
            ->with(['category', 'productVariants:id']);

        if ($customerFacing) {
            $query->whereIn('visibility', KnowledgeArticleVisibility::customerVisibleValues());
        }

        if ($locale !== null) {
            $query->whereIn('locale', array_values(array_unique([$locale, 'en'])));
        }

        $articles = $query
            ->orderByDesc('published_at')
            ->limit(200)
            ->get();

        if ($articles->isEmpty()) {
            return new Collection;
        }

        $typeMap = [];
        $typeRows = DB::table('knowledge_article_ticket_types')
            ->whereIn('knowledge_article_id', $articles->modelKeys())
            ->get(['knowledge_article_id', 'ticket_type']);

        foreach ($typeRows as $row) {
            if (is_numeric($row->knowledge_article_id) && is_string($row->ticket_type)) {
                $typeMap[(int) $row->knowledge_article_id][] = $row->ticket_type;
            }
        }

        $productVariantId = $ticket->serializedInventoryUnit?->product_variant_id;
        $keywords = $this->keywords($ticket->title.' '.$ticket->description);

        return $articles
            ->sortByDesc(fn (KnowledgeArticle $article): int => $this->score(
                $article,
                $ticket,
                $typeMap[$article->id] ?? [],
                $productVariantId,
                $keywords,
            ))
            ->take(max(1, $limit))
            ->values();
    }

    /**
     * @param  list<string>  $ticketTypes  ticket-type values the article is linked to
     * @param  list<string>  $keywords
     */
    private function score(KnowledgeArticle $article, Ticket $ticket, array $ticketTypes, ?int $productVariantId, array $keywords): int
    {
        $score = 1;

        if ($productVariantId !== null
            && $article->productVariants->contains('id', $productVariantId)) {
            $score += 50;
        }

        if (in_array($ticket->type->value, $ticketTypes, true)) {
            $score += 30;
        }

        $title = Str::lower($article->title);
        $summary = Str::lower((string) $article->summary);

        foreach ($keywords as $keyword) {
            if (str_contains($title, $keyword)) {
                $score += 5;
            } elseif (str_contains($summary, $keyword)) {
                $score += 2;
            }
        }

        return $score;
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^\pL\pN]+/u', Str::lower($text));

        if ($words === false) {
            return [];
        }

        return array_slice(array_unique(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= 4,
        )), 0, 20);
    }
}
