<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewBill extends ViewRecord
{
    protected static string $resource = BillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
