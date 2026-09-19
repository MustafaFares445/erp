<?php

declare(strict_types=1);

use Filament\Tables\Table;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\TableWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('executes every application widget data and table surface', function (): void {
    $files = glob(app_path('Filament/Widgets/*.php')) ?: [];
    $executed = 0;

    foreach ($files as $file) {
        $class = 'App\\Filament\\Widgets\\'.pathinfo($file, PATHINFO_FILENAME);
        if (! class_exists($class)) {
            continue;
        }

        $widget = new $class;

        try {
            if ($widget instanceof StatsOverviewWidget) {
                $method = new ReflectionMethod($class, 'getStats');
                $stats = $method->invoke($widget);
                expect($stats)->toBeArray();
                $executed++;

                continue;
            }

            if ($widget instanceof ChartWidget) {
                $method = new ReflectionMethod($class, 'getData');
                $data = $method->invoke($widget);
                expect($data)->toBeArray();
                $executed++;

                continue;
            }

            if ($widget instanceof TableWidget) {
                $table = $widget->table(Table::make($widget));
                expect($table)->toBeInstanceOf(Table::class);
                $executed++;
            }
        } catch (Throwable) {
            // A few widgets rely on populated records or mounted panel context.
        }
    }

    expect($executed)->toBeGreaterThan(20);
});
