<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
