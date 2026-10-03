<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The resolved date window behind every module dashboard's filter bar: the
 * selected window, its equal-length comparison window, and the bucket
 * granularity charts and KPI sparklines group by. Widgets build their
 * queries from one of these rather than reading `$pageFilters` directly, so
 * the preset-to-dates and comparison-window logic exists exactly once.
 *
 * Bucketing happens in PHP with Carbon because SQLite (the test driver) has
 * no `DATE_FORMAT`.
 */
final readonly class DashboardPeriod
{
    public const string PERIOD_TODAY = 'today';

    public const string PERIOD_LAST_7_DAYS = 'last_7_days';

    public const string PERIOD_LAST_30_DAYS = 'last_30_days';

    public const string PERIOD_THIS_MONTH = 'this_month';

    public const string PERIOD_LAST_MONTH = 'last_month';

    public const string PERIOD_THIS_QUARTER = 'this_quarter';

    public const string PERIOD_THIS_YEAR = 'this_year';

    public const string PERIOD_CUSTOM = 'custom';

    public const string DEFAULT_PERIOD = self::PERIOD_LAST_30_DAYS;

    public const string GRANULARITY_HOURLY = 'hourly';

    public const string GRANULARITY_DAILY = 'daily';

    public const string GRANULARITY_WEEKLY = 'weekly';

    public const string GRANULARITY_MONTHLY = 'monthly';

    private const int MAX_BUCKETS = 400;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public string $granularity,
    ) {}

    /** @param  array<string, mixed>  $pageFilters */
    public static function fromPageFilters(array $pageFilters): self
    {
        $period = $pageFilters['period'] ?? null;
        $period = is_string($period) && $period !== '' ? $period : self::DEFAULT_PERIOD;

        [$from, $to] = self::resolvePeriod(
            $period,
            self::nullableString($pageFilters['customFrom'] ?? null),
            self::nullableString($pageFilters['customUntil'] ?? null),
        );

        $lengthInDays = max(1, (int) $from->diffInDays($to) + 1);
        $previousTo = $from->subDay()->endOfDay();
        $previousFrom = $previousTo->subDays($lengthInDays - 1)->startOfDay();

        return new self(
            from: $from,
            to: $to,
            previousFrom: $previousFrom,
            previousTo: $previousTo,
            granularity: self::granularityFor($period, $lengthInDays),
        );
    }

    /** @return array<string, string> */
    public static function presetOptions(): array
    {
        return [
            self::PERIOD_TODAY => __('dashboards.periods.today'),
            self::PERIOD_LAST_7_DAYS => __('dashboards.periods.last_7_days'),
            self::PERIOD_LAST_30_DAYS => __('dashboards.periods.last_30_days'),
            self::PERIOD_THIS_MONTH => __('dashboards.periods.this_month'),
            self::PERIOD_LAST_MONTH => __('dashboards.periods.last_month'),
            self::PERIOD_THIS_QUARTER => __('dashboards.periods.this_quarter'),
            self::PERIOD_THIS_YEAR => __('dashboards.periods.this_year'),
            self::PERIOD_CUSTOM => __('dashboards.periods.custom'),
        ];
    }

    /**
     * The selected window's buckets, oldest first. Pass `previous: true` for
     * the comparison window's buckets.
     *
     * @return list<array{key: string, label: string}>
     */
    public function buckets(bool $previous = false): array
    {
        $cursor = $previous ? $this->previousFrom : $this->from;
        $end = $previous ? $this->previousTo : $this->to;
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end) && count($buckets) < self::MAX_BUCKETS) {
            $buckets[] = ['key' => $this->bucketKey($cursor), 'label' => $this->bucketLabel($cursor)];

            $cursor = match ($this->granularity) {
                self::GRANULARITY_HOURLY => $cursor->addHour(),
                self::GRANULARITY_WEEKLY => $cursor->startOfWeek()->addWeek(),
                self::GRANULARITY_MONTHLY => $cursor->startOfMonth()->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /** @return list<string> */
    public function labels(bool $previous = false): array
    {
        return array_map(static fn (array $bucket): string => $bucket['label'], $this->buckets($previous));
    }

    public function bucketKey(CarbonInterface $timestamp): string
    {
        return match ($this->granularity) {
            self::GRANULARITY_HOURLY => $timestamp->format('Y-m-d H:00'),
            self::GRANULARITY_WEEKLY => CarbonImmutable::instance($timestamp)->startOfWeek()->format('Y-m-d'),
            self::GRANULARITY_MONTHLY => $timestamp->format('Y-m'),
            default => $timestamp->format('Y-m-d'),
        };
    }

    /**
     * Sums `[timestamp, value]` points into this window's buckets. The
     * points are raw column values: anything that is not a date (or a date
     * string) is ignored, as is any point outside the window, and a
     * non-numeric value counts as zero.
     *
     * @param  iterable<array{0: mixed, 1: mixed}>  $points
     * @return list<float>
     */
    public function sumSeries(iterable $points, bool $previous = false): array
    {
        $totals = array_fill_keys(
            array_map(static fn (array $bucket): string => $bucket['key'], $this->buckets($previous)),
            0.0,
        );

        foreach ($points as [$timestamp, $value]) {
            $moment = self::toMoment($timestamp);

            if (! $moment instanceof CarbonImmutable) {
                continue;
            }

            $key = $this->bucketKey($moment);

            if (array_key_exists($key, $totals)) {
                $totals[$key] += self::toFloat($value);
            }
        }

        return array_values($totals);
    }

    /**
     * Counts timestamps (raw column values) into this window's buckets.
     *
     * @param  iterable<mixed>  $timestamps
     * @return list<int>
     */
    public function countSeries(iterable $timestamps, bool $previous = false): array
    {
        $points = [];

        foreach ($timestamps as $timestamp) {
            $points[] = [$timestamp, 1];
        }

        return array_map(intval(...), $this->sumSeries($points, $previous));
    }

    /** A raw aggregate or column value as an integer; anything non-numeric is zero. */
    public static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** A raw aggregate or column value as a float; anything non-numeric is zero. */
    public static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function toMoment(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value),
            default => null,
        };
    }

    private function bucketLabel(CarbonImmutable $cursor): string
    {
        return match ($this->granularity) {
            self::GRANULARITY_HOURLY => $cursor->format('g A'),
            self::GRANULARITY_WEEKLY => __('dashboards.week_of', ['date' => $cursor->startOfWeek()->format('M j')]),
            self::GRANULARITY_MONTHLY => $cursor->format('M Y'),
            default => $cursor->format('M j'),
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function resolvePeriod(string $period, ?string $customFrom, ?string $customUntil): array
    {
        $today = CarbonImmutable::today();

        return match ($period) {
            self::PERIOD_TODAY => [$today->startOfDay(), $today->endOfDay()],
            self::PERIOD_LAST_7_DAYS => [$today->subDays(6)->startOfDay(), $today->endOfDay()],
            self::PERIOD_THIS_MONTH => [$today->startOfMonth(), $today->endOfDay()],
            self::PERIOD_LAST_MONTH => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ],
            self::PERIOD_THIS_QUARTER => [$today->startOfQuarter(), $today->endOfDay()],
            self::PERIOD_THIS_YEAR => [$today->startOfYear(), $today->endOfDay()],
            self::PERIOD_CUSTOM => self::resolveCustomRange($customFrom, $customUntil, $today),
            default => [$today->subDays(29)->startOfDay(), $today->endOfDay()],
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function resolveCustomRange(?string $customFrom, ?string $customUntil, CarbonImmutable $today): array
    {
        $from = $customFrom !== null ? CarbonImmutable::parse($customFrom)->startOfDay() : $today->subDays(29)->startOfDay();
        $to = $customUntil !== null ? CarbonImmutable::parse($customUntil)->endOfDay() : $today->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [$from, $to];
    }

    private static function granularityFor(string $period, int $lengthInDays): string
    {
        return match ($period) {
            self::PERIOD_TODAY => self::GRANULARITY_HOURLY,
            self::PERIOD_THIS_QUARTER => self::GRANULARITY_WEEKLY,
            self::PERIOD_THIS_YEAR => self::GRANULARITY_MONTHLY,
            default => match (true) {
                $lengthInDays <= 31 => self::GRANULARITY_DAILY,
                $lengthInDays <= 120 => self::GRANULARITY_WEEKLY,
                default => self::GRANULARITY_MONTHLY,
            },
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
