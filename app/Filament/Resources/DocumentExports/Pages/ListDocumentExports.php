<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentExports\Pages;

use App\Filament\Resources\DocumentExports\DocumentExportResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentExports extends ListRecords
{
    protected static string $resource = DocumentExportResource::class;
}
