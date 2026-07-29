<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Providers\Filament\AdminPanelServiceProvider;
use Filament\Panel;
use Filament\Support\Enums\Width;

it('uses the full panel width with a two-column dashboard grid', function (): void {
    $panel = new AdminPanelServiceProvider(app())->panel(Panel::make());

    expect($panel->getMaxContentWidth())->toBe(Width::Full)
        ->and((new Dashboard)->getColumns())->toBe(['lg' => 2]);
});

it('makes the recent stock movements widget span the dashboard grid', function (): void {
    $property = new ReflectionProperty(InventoryRecentMovements::class, 'columnSpan');

    expect($property->getValue(new InventoryRecentMovements))->toBe('full');
});
