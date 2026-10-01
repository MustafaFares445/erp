<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Pages;

use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditWarrantyPolicy extends EditRecord
{
    protected static string $resource = WarrantyPolicyResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
