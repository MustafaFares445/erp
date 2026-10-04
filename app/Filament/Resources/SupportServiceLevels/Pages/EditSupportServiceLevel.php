<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels\Pages;

use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportServiceLevel extends EditRecord
{
    protected static string $resource = SupportServiceLevelResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
