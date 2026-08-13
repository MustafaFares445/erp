<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use Filament\Resources\Pages\ListRecords;

final class ListSalesOpportunities extends ListRecords
{
    protected static string $resource = SalesOpportunityResource::class;
}
