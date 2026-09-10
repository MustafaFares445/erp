<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies\Pages;

use App\Filament\Resources\WarehouseReplenishmentPolicies\WarehouseReplenishmentPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageWarehouseReplenishmentPolicies extends ManageRecords
{
    protected static string $resource = WarehouseReplenishmentPolicyResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
