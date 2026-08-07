<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityDrafts\Pages;

use App\Filament\Resources\OpportunityDrafts\OpportunityDraftResource;
use Filament\Resources\Pages\ListRecords;

final class ListOpportunityDrafts extends ListRecords
{
    protected static string $resource = OpportunityDraftResource::class;
}
