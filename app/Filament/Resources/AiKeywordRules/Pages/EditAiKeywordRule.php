<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKeywordRules\Pages;

use App\Filament\Resources\AiKeywordRules\AiKeywordRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

final class EditAiKeywordRule extends EditRecord
{
    protected static string $resource = AiKeywordRuleResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
