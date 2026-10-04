<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticles\Pages;

use App\Enums\KnowledgeArticleStatus;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Services\Support\KnowledgeArticleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EditKnowledgeArticle extends EditRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    /** @var list<string> */
    private array $ticketTypes = [];

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['ticket_types'] = DB::table('knowledge_article_ticket_types')
            ->where('knowledge_article_id', $this->getRecord()->getKey())
            ->pluck('ticket_type')
            ->all();

        return $data;
    }

    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $ticketTypes = $data['ticket_types'] ?? [];
        $this->ticketTypes = is_array($ticketTypes) ? array_values(array_filter($ticketTypes, is_string(...))) : [];
        unset($data['ticket_types']);

        $data['updated_by'] = $this->actor()->getKey();

        return $data;
    }

    protected function afterSave(): void
    {
        $articleId = $this->getRecord()->getKey();

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

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish'))
                ->color('success')
                ->authorize('publish')
                ->visible(fn (): bool => $this->article()->status !== KnowledgeArticleStatus::Published)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(KnowledgeArticleService::class)->publish($this->article(), $this->actor());
                    $this->refreshFormData(['status', 'published_at']);
                    Notification::make()->success()->title(__('Article published'))->send();
                }),
            Action::make('archive')
                ->label(__('Archive'))
                ->color('warning')
                ->authorize('update')
                ->visible(fn (): bool => $this->article()->status !== KnowledgeArticleStatus::Archived)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(KnowledgeArticleService::class)->archive($this->article(), $this->actor());
                    Notification::make()->success()->title(__('Article archived'))->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function article(): KnowledgeArticle
    {
        $record = $this->getRecord();

        if (! $record instanceof KnowledgeArticle) {
            throw new LogicException('Expected a KnowledgeArticle record.');
        }

        return $record;
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
