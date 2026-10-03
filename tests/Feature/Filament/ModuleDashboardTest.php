<?php

declare(strict_types=1);

use App\Filament\Pages\ModuleDashboard;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

final class ModuleDashboardTestVisibleWidget extends Widget
{
    use InteractsWithDashboardFilters;
}

final class ModuleDashboardTestHiddenWidget extends Widget
{
    use InteractsWithDashboardFilters;

    #[Override]
    public static function canView(): bool
    {
        return false;
    }
}

final class ModuleDashboardTestPage extends ModuleDashboard
{
    /** @return list<class-string<Widget>|list<class-string<Widget>>> */
    #[Override]
    protected function getDashboardWidgets(): array
    {
        return [
            ModuleDashboardTestVisibleWidget::class,
            [ModuleDashboardTestVisibleWidget::class, ModuleDashboardTestVisibleWidget::class],
            [ModuleDashboardTestVisibleWidget::class, ModuleDashboardTestHiddenWidget::class],
            [ModuleDashboardTestHiddenWidget::class, ModuleDashboardTestHiddenWidget::class],
        ];
    }

    /** @return list<class-string<Widget>|WidgetConfiguration> */
    public function resolved(): array
    {
        return $this->resolveDashboardWidgets();
    }
}

final class ModuleDashboardTestTrendBuilder
{
    use BuildsTrendStats;

    /** @param  list<float|int>  $series */
    public function build(float|int $current, float|int $previous, array $series = [], bool $higherIsBetter = true): Stat
    {
        return $this->trendStat('Label', (string) $current, $current, $previous, $series, Heroicon::OutlinedBanknotes, '/somewhere', $higherIsBetter);
    }
}

it('keeps pairs side by side and promotes the survivor of a half-hidden pair to full width', function (): void {
    $resolved = (new ModuleDashboardTestPage)->resolved();

    expect($resolved)->toHaveCount(4)
        ->and($resolved[0])->toBe(ModuleDashboardTestVisibleWidget::class)
        ->and($resolved[1])->toBe(ModuleDashboardTestVisibleWidget::class)
        ->and($resolved[2])->toBe(ModuleDashboardTestVisibleWidget::class)
        ->and($resolved[3])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[3]->getProperties())->toBe(['spansFullWidth' => true]);
});

it('spans full width only when the dashboard asks it to', function (): void {
    $widget = new ModuleDashboardTestVisibleWidget;

    expect($widget->getColumnSpan())->toBe(1);

    $widget->spansFullWidth = true;

    expect($widget->getColumnSpan())->toBe('full');
});

it('reads typed values from the page filters', function (): void {
    $widget = new ModuleDashboardTestVisibleWidget;
    $widget->pageFilters = ['period' => 'this_year', 'warehouseId' => '7', 'source' => 'web', 'blank' => ''];

    $read = fn (string $method, string $key): mixed => (fn (): mixed => $this->{$method}($key))->call($widget);

    expect(new ReflectionMethod($widget, 'dashboardPeriod')->invoke($widget)->granularity)->toBe('monthly')
        ->and($read('dashboardFilter', 'warehouseId'))->toBe(7)
        ->and($read('dashboardFilter', 'missing'))->toBeNull()
        ->and($read('dashboardStringFilter', 'source'))->toBe('web')
        ->and($read('dashboardStringFilter', 'blank'))->toBeNull();
});

it('describes the trend against the previous period', function (float|int $current, float|int $previous, bool $higherIsBetter, string $description, string $color): void {
    $stat = (new ModuleDashboardTestTrendBuilder)->build($current, $previous, higherIsBetter: $higherIsBetter);

    expect((string) $stat->getDescription())->toBe($description)
        ->and($stat->getColor())->toBe($color)
        ->and($stat->getUrl())->toBe('/somewhere');
})->with([
    'increase' => [150, 100, true, '50.0% increase', 'success'],
    'decrease' => [50, 100, true, '50.0% decrease', 'danger'],
    'increase that is bad' => [150, 100, false, '50.0% increase', 'danger'],
    'flat' => [100, 100, true, 'No change vs previous period', 'gray'],
    'new' => [5, 0, true, 'New this period', 'gray'],
    'nothing' => [0, 0, true, 'No activity in this period', 'gray'],
]);

it('adds a sparkline only when there is a series to draw', function (): void {
    $builder = new ModuleDashboardTestTrendBuilder;

    expect($builder->build(2, 1, [1, 2, 3])->getChart())->toBe([1, 2, 3])
        ->and($builder->build(2, 1, [1, 2, 3])->getChartColor())->toBe('success')
        ->and($builder->build(1, 1, [1, 1])->getChartColor())->toBe('primary')
        ->and($builder->build(2, 1, [5])->getChart())->toBeNull();
});
