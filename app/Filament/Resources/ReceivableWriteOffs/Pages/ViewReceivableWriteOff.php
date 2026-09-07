<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Pages;

use App\Filament\Resources\ReceivableWriteOffs\Actions\ReceivableWriteOffActions;
use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewReceivableWriteOff extends ViewRecord
{
    protected static string $resource = ReceivableWriteOffResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ReceivableWriteOffActions::approve(),
            ReceivableWriteOffActions::cancel(),
        ];
    }
}
