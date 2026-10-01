<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Pages;

use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListWarrantyPolicies extends ListRecords
{
    protected static string $resource = WarrantyPolicyResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New warranty policy')];
    }
}
