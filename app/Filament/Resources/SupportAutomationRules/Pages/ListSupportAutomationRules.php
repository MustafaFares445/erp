<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportAutomationRules\Pages;

use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportAutomationRules extends ListRecords
{
    protected static string $resource = SupportAutomationRuleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
