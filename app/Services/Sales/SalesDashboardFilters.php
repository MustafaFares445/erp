<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Carbon\CarbonImmutable;

/**
 * Resolved state of the Sales Dashboard's global filter bar: a concrete
 * date window (plus its equal-length comparison window) and the optional
 * salesperson/customer scoping. Every dashboard widget builds its queries
 * from one of these rather than reading `$pageFilters` directly, so the
 * period-preset-to-dates and comparison-window logic exists exactly once.
 */
final readonly class SalesDashboardFilters
{
    public const string PERIOD_TODAY = 'today';

    public const string PERIOD_LAST_7_DAYS = 'last_7_days';

    public const string PERIOD_LAST_30_DAYS = 'last_30_days';

    public const string PERIOD_THIS_MONTH = 'this_month';

    public const string PERIOD_LAST_MONTH = 'last_month';

    public const string PERIOD_THIS_QUARTER = 'this_quarter';

    public const string PERIOD_THIS_YEAR = 'this_year';

    public const string PERIOD_CUSTOM = 'custom';

    public const string GRANULARITY_HOURLY = 'hourly';

    public const string GRANULARITY_DAILY = 'daily';

    public const string GRANULARITY_WEEKLY = 'weekly';

    public const string GRANULARITY_MONTHLY = 'monthly';

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public string $granularity,
        public ?int $employeeId = null,
        public ?int $customerId = null,
    ) {}

    /** @param  array<string, mixed>  $pageFilters */
    public static function fromPageFilters(array $pageFilters): self
    {
        $period = $pageFilters['period'] ?? null;
        $period = is_string($period) && $period !== '' ? $period : self::PERIOD_LAST_30_DAYS;

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
            employeeId: self::nullableInt($pageFilters['employeeId'] ?? null),
            customerId: self::nullableInt($pageFilters['customerId'] ?? null),
        );
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

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
