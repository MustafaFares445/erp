<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules\Pages;

use App\Filament\Resources\AiKeywordRules\AiKeywordRuleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiKeywordRule extends CreateRecord
{
    protected static string $resource = AiKeywordRuleResource::class;
}
