<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules\Pages;

use App\Filament\Resources\AiKeywordRules\AiKeywordRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiKeywordRules extends ListRecords
{
    protected static string $resource = AiKeywordRuleResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
