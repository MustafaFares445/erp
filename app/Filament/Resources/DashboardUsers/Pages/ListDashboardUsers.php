<?php

declare(strict_types=1);

namespace App\Filament\Resources\DashboardUsers\Pages;

use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use Filament\Resources\Pages\ListRecords;

final class ListDashboardUsers extends ListRecords
{
    protected static string $resource = DashboardUserResource::class;
}
