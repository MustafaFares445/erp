<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\SupportPermission;
use App\Enums\TicketKnowledgeLinkType;
use App\Models\KnowledgeArticle;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KnowledgeArticleService
{
    public function publish(KnowledgeArticle $article, User $actor): KnowledgeArticle
    {
        abort_unless($actor->can(SupportPermission::KnowledgePublish->value), 403);

        return DB::transaction(function () use ($article, $actor): KnowledgeArticle {
            $article->forceFill([
                'status' => KnowledgeArticleStatus::Published,
                'published_at' => $article->published_at ?? now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($article)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.knowledge.published');

            return $article->refresh();
        });
    }

    public function archive(KnowledgeArticle $article, User $actor): KnowledgeArticle
    {
        abort_unless($actor->can(SupportPermission::KnowledgeManage->value), 403);

        $article->forceFill([
            'status' => KnowledgeArticleStatus::Archived,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $article->refresh();
    }

    public function shareWithCustomer(Ticket $ticket, KnowledgeArticle $article, User $actor): TicketKnowledgeArticle
    {
        abort_unless($actor->can(SupportPermission::KnowledgeView->value), 403);

        if (! $actor->can('message', $ticket)) {
            abort(403);
        }

        if ($article->status !== KnowledgeArticleStatus::Published
            || ! in_array($article->visibility, [KnowledgeArticleVisibility::Customer, KnowledgeArticleVisibility::Both], true)) {
            throw new DomainException('Only published customer-visible knowledge articles can be shared.');
        }

        return DB::transaction(function () use ($ticket, $article, $actor): TicketKnowledgeArticle {
            $link = TicketKnowledgeArticle::query()->firstOrCreate(
                [
                    'ticket_id' => $ticket->getKey(),
                    'knowledge_article_id' => $article->getKey(),
                    'link_type' => TicketKnowledgeLinkType::SharedWithCustomer->value,
                ],
                ['linked_by' => $actor->getKey()],
            );

            app(TicketMessageService::class)->post(
                $ticket,
                __('Knowledge article shared: :title', ['title' => $article->title]),
                false,
                $actor,
            );

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withProperties([
                    'knowledge_article_id' => $article->getKey(),
                    'source_channel' => 'dashboard',
                ])
                ->log('support.knowledge.shared');

            return $link;
        });
    }

    public function markUsedInResolution(Ticket $ticket, KnowledgeArticle $article, User $actor): TicketKnowledgeArticle
    {
        abort_unless($actor->can(SupportPermission::KnowledgeView->value), 403);

        return TicketKnowledgeArticle::query()->firstOrCreate(
            [
                'ticket_id' => $ticket->getKey(),
                'knowledge_article_id' => $article->getKey(),
                'link_type' => TicketKnowledgeLinkType::UsedInResolution->value,
            ],
            ['linked_by' => $actor->getKey()],
        );
    }

    public function ensureSlug(KnowledgeArticle $article): void
    {
        if (filled($article->slug)) {
            return;
        }

        $base = Str::slug($article->title);
        $slug = $base !== '' ? $base : 'article';
        $candidate = $slug;
        $suffix = 2;

        while (KnowledgeArticle::query()
            ->where('slug', $candidate)
            ->when($article->exists, fn (Builder $query) => $query->where($article->getKeyName(), '!=', $article->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        $article->slug = $candidate;
    }
}
