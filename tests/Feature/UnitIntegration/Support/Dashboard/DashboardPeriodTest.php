<?php

declare(strict_types=1);

use App\Support\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-30 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('defaults to the last 30 days with an equal-length previous window', function (): void {
    $period = DashboardPeriod::fromPageFilters([]);

    expect($period->from->toDateString())->toBe('2026-09-01')
        ->and($period->to->toDateString())->toBe('2026-09-30')
        ->and($period->previousTo->toDateString())->toBe('2026-08-31')
        ->and($period->previousFrom->toDateString())->toBe('2026-08-02')
        ->and($period->granularity)->toBe(DashboardPeriod::GRANULARITY_DAILY)
        ->and($period->buckets())->toHaveCount(30)
        ->and($period->buckets(previous: true))->toHaveCount(30);
});

it('offers every preset as a translated option', function (): void {
    expect(DashboardPeriod::presetOptions())
        ->toHaveKeys([
            DashboardPeriod::PERIOD_TODAY,
            DashboardPeriod::PERIOD_LAST_7_DAYS,
            DashboardPeriod::PERIOD_LAST_30_DAYS,
            DashboardPeriod::PERIOD_THIS_MONTH,
            DashboardPeriod::PERIOD_LAST_MONTH,
            DashboardPeriod::PERIOD_THIS_QUARTER,
            DashboardPeriod::PERIOD_THIS_YEAR,
            DashboardPeriod::PERIOD_CUSTOM,
        ])
        ->each->not->toStartWith('dashboards.');
});

it('labels buckets by granularity', function (string $period, int $count, string $firstLabel): void {
    $resolved = DashboardPeriod::fromPageFilters(['period' => $period]);

    expect($resolved->buckets())->toHaveCount($count)
        ->and($resolved->labels()[0])->toBe($firstLabel);
})->with([
    'hourly' => [DashboardPeriod::PERIOD_TODAY, 24, '12 AM'],
    'daily' => [DashboardPeriod::PERIOD_LAST_7_DAYS, 7, 'Sep 24'],
    'weekly' => [DashboardPeriod::PERIOD_THIS_QUARTER, 14, 'Week of Jun 29'],
    'monthly' => [DashboardPeriod::PERIOD_THIS_YEAR, 9, 'Jan 2026'],
]);

it('keeps a trailing partial week as its own bucket', function (): void {
    // Wednesday 2026-01-07 to Tuesday 2026-04-14 is weekly; the final Monday
    // (2026-04-13) must still get a bucket even though no Wednesday follows.
    $period = DashboardPeriod::fromPageFilters([
        'period' => DashboardPeriod::PERIOD_CUSTOM,
        'customFrom' => '2026-01-07',
        'customUntil' => '2026-04-14',
    ]);

    $keys = array_column($period->buckets(), 'key');

    expect($period->granularity)->toBe(DashboardPeriod::GRANULARITY_WEEKLY)
        ->and($keys[0])->toBe('2026-01-05')
        ->and(end($keys))->toBe('2026-04-13');
});

it('swaps an inverted custom range', function (): void {
    $period = DashboardPeriod::fromPageFilters([
        'period' => DashboardPeriod::PERIOD_CUSTOM,
        'customFrom' => '2026-09-20',
        'customUntil' => '2026-09-10',
    ]);

    expect($period->from->toDateString())->toBe('2026-09-10')
        ->and($period->to->toDateString())->toBe('2026-09-20');
});

it('sums and counts points into the selected or previous window', function (): void {
    $period = DashboardPeriod::fromPageFilters(['period' => DashboardPeriod::PERIOD_LAST_7_DAYS]);

    $sums = $period->sumSeries([
        ['2026-09-24 08:00:00', '10.50'],
        [CarbonImmutable::parse('2026-09-24 18:00:00'), 4],
        ['2026-09-30 09:00:00', 'not-a-number'],
        ['2026-09-01 09:00:00', 99],
        [null, 99],
    ]);

    $counts = $period->countSeries(['2026-09-29 10:00:00', '2026-09-29 11:00:00', '', '2026-09-20 10:00:00']);
    $previousCounts = $period->countSeries(['2026-09-20 10:00:00'], previous: true);

    expect($sums)->toBe([14.5, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($counts)->toBe([0, 0, 0, 0, 0, 2, 0])
        ->and(array_sum($previousCounts))->toBe(1);
});
