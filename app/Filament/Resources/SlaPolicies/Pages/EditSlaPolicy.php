<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Resources\Pages\EditRecord;

final class EditSlaPolicy extends EditRecord
{
    protected static string $resource = SlaPolicyResource::class;
}
