<?php

declare(strict_types=1);

use App\Filament\Widgets\InventoryRecentMovements;
use App\Providers\Filament\AdminPanelServiceProvider;
use Filament\Panel;
use Filament\Support\Enums\Width;

it('does not register the removed standalone dashboard and keeps the full panel width', function (): void {
    $panel = new AdminPanelServiceProvider(app())->panel(Panel::make());

    expect($panel->getMaxContentWidth())->toBe(Width::Full)
        ->and($panel->getPages())->not->toContain('App\\Filament\\Pages\\Dashboard');
});

it('sits the recent stock movements widget in one half of a dashboard row unless its partner is hidden', function (): void {
    $widget = new InventoryRecentMovements;

    expect($widget->getColumnSpan())->toBe(1);

    $widget->spansFullWidth = true;

    expect($widget->getColumnSpan())->toBe('full');
});
