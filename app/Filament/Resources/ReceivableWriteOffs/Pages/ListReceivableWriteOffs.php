<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs\Pages;

use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListReceivableWriteOffs extends ListRecords
{
    protected static string $resource = ReceivableWriteOffResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
