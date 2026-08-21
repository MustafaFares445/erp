<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Schemas\PerformanceProgressBar;
use App\Filament\Resources\MonthlyPlans\Schemas\SalesPlanStageBar;
use Filament\Schemas\Schema;

it('renders empty markup for the performance progress bar without a bound record', function (): void {
    $component = PerformanceProgressBar::make();
    $schema = Schema::make()->components([$component]);
    $schema->getComponents();

    expect((string) $component->getContent())->toBe('');
});

it('renders empty markup for the sales plan stage bar without a bound record', function (): void {
    $component = SalesPlanStageBar::make();
    $schema = Schema::make()->components([$component]);
    $schema->getComponents();

    expect((string) $component->getContent())->toBe('');
});
