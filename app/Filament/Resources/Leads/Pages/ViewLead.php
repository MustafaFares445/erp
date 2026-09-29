<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Lead $record): bool => ! $record->status->isTerminal()),
        ];
    }
}
