<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
