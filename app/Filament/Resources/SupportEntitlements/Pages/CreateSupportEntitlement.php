<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEntitlements\Pages;

use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportEntitlement extends CreateRecord
{
    protected static string $resource = SupportEntitlementResource::class;
}
