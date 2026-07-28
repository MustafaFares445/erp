<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageTypes\Pages;

use App\Filament\Resources\PackageTypes\PackageTypeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePackageType extends CreateRecord
{
    protected static string $resource = PackageTypeResource::class;
}
