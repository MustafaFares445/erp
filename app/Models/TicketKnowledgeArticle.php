<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketKnowledgeLinkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'knowledge_article_id', 'linked_by', 'link_type'])]
final class TicketKnowledgeArticle extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['link_type' => TicketKnowledgeLinkType::class];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<KnowledgeArticle, $this> */
    public function knowledgeArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
