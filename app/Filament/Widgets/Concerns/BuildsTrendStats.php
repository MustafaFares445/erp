<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Builds the dashboard KPI card: value, "±x% vs previous period" trend with
 * icon and colour, and a sparkline of the selected window.
 */
trait BuildsTrendStats
{
    /**
     * @param  list<float|int>  $series
     */
    protected function trendStat(
        string $label,
        string $value,
        float|int $current,
        float|int $previous,
        array $series = [],
        ?Heroicon $icon = null,
        ?string $url = null,
        bool $higherIsBetter = true,
    ): Stat {
        $change = self::changePercent($current, $previous);
        $color = self::trendColor($change, $higherIsBetter);

        $stat = Stat::make($label, $value)
            ->description(self::trendDescription($change, $current))
            ->descriptionIcon(self::trendIcon($change))
            ->color($color)
            ->icon($icon)
            ->url($url);

        if (count($series) > 1) {
            $stat->chart($series)->chartColor($color === 'gray' ? 'primary' : $color);
        }

        return $stat;
    }

    protected static function changePercent(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    private static function trendDescription(?float $change, float|int $current): string
    {
        if ($change === null) {
            return (float) $current === 0.0
                ? __('dashboards.trend.no_activity')
                : __('dashboards.trend.new_this_period');
        }

        if ($change === 0.0) {
            return __('dashboards.trend.no_change');
        }

        return __($change > 0 ? 'dashboards.trend.increase' : 'dashboards.trend.decrease', [
            'percent' => number_format(abs($change), 1),
        ]);
    }

    private static function trendIcon(?float $change): ?Heroicon
    {
        return match (true) {
            $change === null, $change === 0.0 => null,
            $change > 0 => Heroicon::OutlinedArrowTrendingUp,
            default => Heroicon::OutlinedArrowTrendingDown,
        };
    }

    private static function trendColor(?float $change, bool $higherIsBetter): string
    {
        if ($change === null || $change === 0.0) {
            return 'gray';
        }

        return ($change > 0) === $higherIsBetter ? 'success' : 'danger';
    }
}
