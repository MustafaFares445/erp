<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarrantyPolicies\Pages;

use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateWarrantyPolicy extends CreateRecord
{
    protected static string $resource = WarrantyPolicyResource::class;
}
