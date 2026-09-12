<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelServiceProvider;
use App\Providers\Filament\DocumentExportPanelServiceProvider;

return [
    AppServiceProvider::class,
    DocumentExportPanelServiceProvider::class,
    AdminPanelServiceProvider::class,
];
