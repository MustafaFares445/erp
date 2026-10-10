<?php

declare(strict_types=1);

use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;

it('uses business-facing Support resource headings in both locales', function (): void {
    $previous = app()->getLocale();

    try {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            expect(MaintenanceRequestResource::getPluralModelLabel())
                ->toBe(__('admin.resources.maintenance_requests'))
                ->and(ServiceRecordResource::getPluralModelLabel())
                ->toBe(__('admin.resources.service_records'))
                ->and((new ListMaintenanceRequests)->getTitle())
                ->toBe(__('admin.resources.maintenance_requests'))
                ->and((new ListServiceRecords)->getTitle())
                ->toBe(__('admin.resources.service_records'));
        }
    } finally {
        app()->setLocale($previous);
    }
});
