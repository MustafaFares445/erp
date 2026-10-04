<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleCategories\Pages;

use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditKnowledgeArticleCategory extends EditRecord
{
    protected static string $resource = KnowledgeArticleCategoryResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
