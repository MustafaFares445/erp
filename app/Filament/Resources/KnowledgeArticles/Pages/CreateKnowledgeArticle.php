<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticles\Pages;

use App\Enums\KnowledgeArticleStatus;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CreateKnowledgeArticle extends CreateRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    /** @var list<string> */
    private array $ticketTypes = [];

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $ticketTypes = $data['ticket_types'] ?? [];
        $this->ticketTypes = is_array($ticketTypes) ? array_values(array_filter($ticketTypes, is_string(...))) : [];
        unset($data['ticket_types']);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        $data['author_id'] = $actor->getKey();
        $data['updated_by'] = $actor->getKey();
        $data['status'] = KnowledgeArticleStatus::Draft->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncTicketTypes();
    }

    private function syncTicketTypes(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof KnowledgeArticle) {
            throw new LogicException('Expected a KnowledgeArticle record.');
        }

        $articleId = $record->getKey();

        DB::table('knowledge_article_ticket_types')
            ->where('knowledge_article_id', $articleId)
            ->delete();

        foreach ($this->ticketTypes as $type) {
            DB::table('knowledge_article_ticket_types')->insert([
                'knowledge_article_id' => $articleId,
                'ticket_type' => $type,
            ]);
        }
    }
}
