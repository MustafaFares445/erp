<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductReferences\Pages;

use App\Filament\Pages\ResourceCalendarPage;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;

final class SupplierProductReferencesCalendar extends ResourceCalendarPage
{
    protected static string $resource = SupplierProductReferenceResource::class;

    protected static string $calendarKey = 'supplier-product-references';
}
