<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Pages;

use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportEntitlements extends ListRecords
{
    protected static string $resource = SupportEntitlementResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
