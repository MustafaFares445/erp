<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleCategories\Pages;

use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateKnowledgeArticleCategory extends CreateRecord
{
    protected static string $resource = KnowledgeArticleCategoryResource::class;
}
