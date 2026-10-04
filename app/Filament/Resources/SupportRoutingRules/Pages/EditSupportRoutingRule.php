<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules\Pages;

use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportRoutingRule extends EditRecord
{
    protected static string $resource = SupportRoutingRuleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
