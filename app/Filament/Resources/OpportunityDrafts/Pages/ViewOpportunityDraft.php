<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityDrafts\Pages;

use App\Filament\Resources\OpportunityDrafts\OpportunityDraftResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewOpportunityDraft extends ViewRecord
{
    protected static string $resource = OpportunityDraftResource::class;
}
