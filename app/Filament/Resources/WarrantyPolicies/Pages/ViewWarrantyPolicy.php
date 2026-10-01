<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Pages;

use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewWarrantyPolicy extends ViewRecord
{
    protected static string $resource = WarrantyPolicyResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
