<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
