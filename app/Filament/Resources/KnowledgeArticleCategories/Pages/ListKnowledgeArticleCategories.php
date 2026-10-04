<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleCategories\Pages;

use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListKnowledgeArticleCategories extends ListRecords
{
    protected static string $resource = KnowledgeArticleCategoryResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
