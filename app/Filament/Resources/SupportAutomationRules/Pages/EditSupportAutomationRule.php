<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules\Pages;

use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportAutomationRule extends EditRecord
{
    protected static string $resource = SupportAutomationRuleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
