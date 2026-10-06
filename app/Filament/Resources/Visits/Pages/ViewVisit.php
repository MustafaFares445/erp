<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\Actions\VisitManagementActions;
use App\Filament\Resources\Visits\VisitResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewVisit extends ViewRecord
{
    protected static string $resource = VisitResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            VisitManagementActions::reschedule(),
            VisitManagementActions::review(),
        ];
    }
}
