<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSalesOpportunity extends ViewRecord
{
    protected static string $resource = SalesOpportunityResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
