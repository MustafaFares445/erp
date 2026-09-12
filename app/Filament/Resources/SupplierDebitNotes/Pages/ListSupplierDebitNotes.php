<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierDebitNotes\Pages;

use App\Filament\Resources\SupplierDebitNotes\SupplierDebitNoteResource;
use Filament\Resources\Pages\ListRecords;

final class ListSupplierDebitNotes extends ListRecords
{
    protected static string $resource = SupplierDebitNoteResource::class;
}
