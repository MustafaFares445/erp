<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Pages;

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Models\SalesOpportunity;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditSalesOpportunity extends EditRecord
{
    protected static string $resource = SalesOpportunityResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof SalesOpportunity) { throw new LogicException('Expected a sales opportunity.'); }
        $record->update($data);
        return $record->refresh();
    }
}
