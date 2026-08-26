<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes\Pages;

use App\Filament\Resources\Taxes\TaxResource;
use Filament\Resources\Pages\ListRecords;

final class ListTaxes extends ListRecords
{
    protected static string $resource = TaxResource::class;
}
