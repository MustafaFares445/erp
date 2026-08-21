<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use Filament\Resources\Pages\ListRecords;

final class ListServiceRecords extends ListRecords
{
    protected static string $resource = ServiceRecordResource::class;
}
