<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReplenishmentRequirements\Pages;

use App\Filament\Resources\ReplenishmentRequirements\ReplenishmentRequirementResource;
use Filament\Resources\Pages\ListRecords;

final class ListReplenishmentRequirements extends ListRecords
{
    protected static string $resource = ReplenishmentRequirementResource::class;

    #[\Override]
    public function getSubheading(): string
    {
        return 'Durable replenishment demand after confirmed incoming coverage is considered.';
    }
}
