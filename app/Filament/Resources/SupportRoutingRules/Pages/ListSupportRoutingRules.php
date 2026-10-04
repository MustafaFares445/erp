<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportRoutingRules\Pages;

use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportRoutingRules extends ListRecords
{
    protected static string $resource = SupportRoutingRuleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
