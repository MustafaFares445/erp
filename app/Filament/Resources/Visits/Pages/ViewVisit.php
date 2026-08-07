<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewVisit extends ViewRecord
{
    protected static string $resource = VisitResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Field-edit'),
        ];
    }
}
