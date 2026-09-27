<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Resources\Pages\ViewRecord;

final class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    #[\Override]
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Supplier
            ? $record->name
            : 'Supplier';
    }
}
