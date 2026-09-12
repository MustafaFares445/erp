<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Resources\DocumentExports\DocumentExportResource;
use Filament\Panel;
use Illuminate\Support\ServiceProvider;

final class DocumentExportPanelServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->resources([DocumentExportResource::class]);
        });
    }
}
