<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Pages;

use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportEntitlement extends EditRecord
{
    protected static string $resource = SupportEntitlementResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
